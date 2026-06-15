<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

/**
 * PlayoutService — manages the channel output playlist.
 *
 * LIVE mode:
 *   No ffmpeg process needed. push reads live.m3u8 directly from ingest.
 *   playout_status = 'live', playout_pid = null.
 *
 * FALLBACK mode:
 *   Runs one ffmpeg process that loops a recording → playout.m3u8.
 *   Push reads playout.m3u8.
 *   playout_status = 'fallback', playout_pid = <pid>.
 *
 * The public method outputPlaylist() returns the path push should read.
 */
class PlayoutService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    // ── Switch to live (no process needed, kill any fallback loop) ────────────

    public function switchToLive(Channel $channel): void
    {
        $this->stopFallbackProcess($channel);
        $channel->update(['playout_pid' => null, 'playout_status' => 'live']);
        Log::info("[Playout] {$channel->name} switched to live");
    }

    // ── Switch to fallback (start ffmpeg loop) ────────────────────────────────

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

        $channel->update(['playout_pid' => $pid, 'playout_status' => 'fallback']);
        Log::info("[Playout] {$channel->name} fallback started — PID {$pid} — {$file}");
        return true;
    }

    // ── Stop everything ───────────────────────────────────────────────────────

    public function stop(Channel $channel): void
    {
        $this->stopFallbackProcess($channel);
        @unlink($channel->dvr_directory . '/playout.m3u8');
        // Clean up playout segments
        foreach (glob($channel->dvr_directory . '/playout_*.ts') ?: [] as $f) {
            @unlink($f);
        }
        $channel->update(['playout_pid' => null, 'playout_status' => 'stopped']);
    }

    // ── State ─────────────────────────────────────────────────────────────────

    public function isFallbackRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'playout'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function hasFallback(Channel $channel): bool
    {
        return $this->resolveFallbackFile($channel) !== null;
    }

    /**
     * Returns the playlist path that PushService should read.
     * Live  → live.m3u8  (written by ingest)
     * Fallback → playout.m3u8 (written by fallback ffmpeg loop)
     */
    public function outputPlaylist(Channel $channel): string
    {
        if ($channel->playout_status === 'fallback') {
            return $channel->dvr_directory . '/playout.m3u8';
        }
        return $channel->dvr_directory . '/live.m3u8';
    }

    // ── Internal ──────────────────────────────────────────────────────────────

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
