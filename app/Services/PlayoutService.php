<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

/**
 * PlayoutService — manages the channel output playlist.
 *
 * Push ALWAYS reads output.m3u8 (a stable path that never changes).
 * This service manages what output.m3u8 points to via a symlink:
 *
 *   LIVE mode:
 *     output.m3u8 → live.m3u8  (written by ingest — no ffmpeg process)
 *     playout_status = 'live', playout_pid = null
 *
 *   FALLBACK mode:
 *     output.m3u8 → playout.m3u8  (written by ffmpeg looping a recording)
 *     playout_status = 'fallback', playout_pid = <pid>
 *
 * The symlink is swapped atomically. Push never restarts — ffmpeg's HLS
 * demuxer picks up the new playlist content on its next refresh cycle
 * (typically within one segment duration).
 */
class PlayoutService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    /**
     * Returns the stable playlist path that push always reads.
     * This path NEVER changes — only its symlink target does.
     */
    public function outputPlaylist(Channel $channel): string
    {
        return $channel->dvr_directory . '/output.m3u8';
    }

    // ── Switch to live (symlink → live.m3u8, kill any fallback loop) ──

    public function switchToLive(Channel $channel): void
    {
        $link  = $this->outputPlaylist($channel);
        $live  = $channel->dvr_directory . '/live.m3u8';

        @unlink($link);
        if (file_exists($live)) {
            symlink('live.m3u8', $link);
        }

        $this->stopFallbackProcess($channel);
        $channel->update(['playout_pid' => null, 'playout_status' => 'live']);
        Log::info("[Playout] {$channel->name} switched to live → output.m3u8");
    }

    // ── Switch to fallback (start ffmpeg loop, symlink → playout.m3u8) ──

    public function switchToFallback(Channel $channel): bool
    {
        $files = $this->resolveFallbackFiles($channel);
        if (empty($files)) {
            Log::warning("[Playout] {$channel->name}: no fallback files available");
            return false;
        }

        $this->stopFallbackProcess($channel);

        $concatFile = $this->buildConcatList($channel, $files);
        $pidFile    = $this->ffmpeg->pidFile($channel, 'playout');
        $logFile    = $this->ffmpeg->logFile($channel, 'playout');

        try {
            $pid = $this->ffmpeg->startProcess(
                $this->buildFallbackCommand($channel, $concatFile),
                $pidFile,
                $logFile,
                6
            );
        } catch (\Throwable $e) {
            Log::error("[Playout] {$channel->name} fallback failed: {$e->getMessage()}");
            $channel->update(['playout_pid' => null, 'playout_status' => 'error']);
            return false;
        }

        $link = $this->outputPlaylist($channel);
        @unlink($link);
        symlink('playout.m3u8', $link);

        $fileList = implode(', ', array_map('basename', $files));
        $channel->update(['playout_pid' => $pid, 'playout_status' => 'fallback']);
        Log::info("[Playout] {$channel->name} fallback loop started — PID {$pid} — [{$fileList}]");
        return true;
    }

    // ── Stop everything ─────────────────────────────────────────────────

    public function stop(Channel $channel): void
    {
        $this->stopFallbackProcess($channel);
        @unlink($this->outputPlaylist($channel));
        @unlink($channel->dvr_directory . '/playout.m3u8');
        foreach (glob($channel->dvr_directory . '/playout_*.ts') ?: [] as $f) {
            @unlink($f);
        }
        $channel->update(['playout_pid' => null, 'playout_status' => 'stopped']);
    }

    // ── State ───────────────────────────────────────────────────────────

    public function isFallbackRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'playout'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function hasFallback(Channel $channel): bool
    {
        return !empty($this->resolveFallbackFiles($channel));
    }

    // ── Internal ────────────────────────────────────────────────────────

    private function stopFallbackProcess(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'playout');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
    }

    private function buildFallbackCommand(Channel $channel, string $concatFile): array
    {
        $dvrDir     = $channel->dvr_directory;
        $segPattern = "{$dvrDir}/playout_%05d.ts";
        $m3u8Out    = "{$dvrDir}/playout.m3u8";
        $segDur     = max(1, (int) $channel->segment_duration);

        return [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-stream_loop', '-1',   // loop the entire concat list indefinitely
            '-re',
            '-safe',  '0',
            '-f',     'concat',
            '-i',     $concatFile,
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-f',                    'hls',
            '-hls_time',             (string) $segDur,
            '-hls_list_size',        '10',
            '-hls_flags',            'delete_segments+append_list',
            '-hls_delete_threshold', '2',
            '-hls_segment_type',     'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache',      '0',
            $m3u8Out,
        ];
    }

    /**
     * Write a concat list file: recordings oldest-first, then slate if available.
     * Returns the path to the concat file.
     */
    private function buildConcatList(Channel $channel, array $files): string
    {
        $path  = $channel->dvr_directory . '/playout_concat.txt';
        $lines = array_map(fn($f) => "file '" . str_replace("'", "'\\''", $f) . "'", $files);
        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    /**
     * Returns ordered list of files to loop: recordings oldest→newest, then slate.
     * Falls back to slate-only if no recordings exist.
     */
    private function resolveFallbackFiles(Channel $channel): array
    {
        $files = [];

        // Collect all completed recordings, oldest first
        $recs = glob($channel->dvr_directory . '/rec_*.mp4') ?: [];
        usort($recs, fn($a, $b) => filemtime($a) - filemtime($b)); // oldest first
        foreach ($recs as $f) {
            if (file_exists($f) && filesize($f) > 1024) {
                $files[] = $f;
            }
        }

        // Always append slate as final entry so there is always something to show
        $slate = $channel->dvr_directory . '/slate.mp4';
        if (!file_exists($slate) || filesize($slate) < 1024) {
            try {
                app(\App\Console\Commands\GenerateSlate::class)->generateSlate($channel);
            } catch (\Throwable $e) {
                Log::error("[Playout] Slate generation failed: {$e->getMessage()}");
            }
        }
        if (file_exists($slate) && filesize($slate) > 1024) {
            $files[] = $slate;
        }

        return $files;
    }
}
