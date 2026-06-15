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
        $file = $this->resolveFallbackFile($channel);
        if (!$file) {
            Log::warning("[Playout] {$channel->name}: no fallback file available");
            return false;
        }

        $this->stopFallbackProcess($channel);

        $pidFile = $this->ffmpeg->pidFile($channel, 'playout');
        $logFile = $this->ffmpeg->logFile($channel, 'playout');

        try {
            $pid = $this->ffmpeg->startProcess(
                $this->buildFallbackCommand($channel, $file),
                $pidFile,
                $logFile,
                6
            );
        } catch (\Throwable $e) {
            Log::error("[Playout] {$channel->name} fallback failed: {$e->getMessage()}");
            $channel->update(['playout_pid' => null, 'playout_status' => 'error']);
            return false;
        }

        // Atomically swap the symlink — push picks up playout.m3u8 on next refresh
        $link = $this->outputPlaylist($channel);
        @unlink($link);
        symlink('playout.m3u8', $link);

        $channel->update(['playout_pid' => $pid, 'playout_status' => 'fallback']);
        Log::info("[Playout] {$channel->name} fallback started — PID {$pid} — {$file}");
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
        return $this->resolveFallbackFile($channel) !== null;
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

    private function buildFallbackCommand(Channel $channel, string $file): array
    {
        $dvrDir     = $channel->dvr_directory;
        $segPattern = "{$dvrDir}/playout_%05d.ts";
        $m3u8Out    = "{$dvrDir}/playout.m3u8";
        $segDur     = max(1, (int) $channel->segment_duration);

        return [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-stream_loop', '-1',
            '-re',
            '-i', $file,
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-f',                    'hls',
            '-hls_time',             (string) $segDur,
            '-hls_list_size',        '10',
            '-hls_flags',            'delete_segments+omit_endlist',
            '-hls_delete_threshold', '2',
            '-hls_segment_type',     'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache',      '0',
            $m3u8Out,
        ];
    }

    private function resolveFallbackFile(Channel $channel): ?string
    {
        if ($channel->fallback_recording_path
            && file_exists($channel->fallback_recording_path)
            && filesize($channel->fallback_recording_path) > 1024) {
            return $channel->fallback_recording_path;
        }

        $files = glob($channel->dvr_directory . '/rec_*.mp4') ?: [];
        if (empty($files)) {
            return null;
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $file = $files[0];
        return (filesize($file) > 1024) ? $file : null;
    }
}
