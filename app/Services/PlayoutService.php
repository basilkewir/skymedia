<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

/**
 * PlayoutService — the HLS playout layer between ingest and push.
 *
 * Owns a single ffmpeg process that reads either:
 *   LIVE     — live.m3u8  (produced by ingest)
 *   FALLBACK — rec_*.mp4  (latest completed recording, looped)
 *
 * In both cases it writes a continuous HLS output to playout.m3u8.
 * PushService always reads playout.m3u8 — it never touches source
 * selection or fallback logic.
 *
 * Playout PID is stored in channels.playout_pid.
 * Playout status is stored in channels.playout_status:
 *   idle | starting | live | fallback | error | stopped
 */
class PlayoutService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    // ── Start live playout (reads live.m3u8 from ingest) ─────────────────────

    public function startLive(Channel $channel): bool
    {
        if (!file_exists($channel->dvr_directory . '/live.m3u8')) {
            return false;
        }

        return $this->launch($channel, $this->buildLiveCommand($channel), 'live');
    }

    // ── Start fallback playout (loops latest completed recording) ────────────

    public function startFallback(Channel $channel): bool
    {
        $file = $this->resolveFallbackFile($channel);
        if (!$file) {
            return false;
        }

        return $this->launch($channel, $this->buildFallbackCommand($channel, $file), 'fallback');
    }

    // ── Stop playout ─────────────────────────────────────────────────────────

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'playout');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }

        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['playout_pid' => null, 'playout_status' => 'stopped']);

        // Remove the playout playlist so push doesn't read a stale one
        @unlink($channel->dvr_directory . '/playout.m3u8');
    }

    // ── State ─────────────────────────────────────────────────────────────────

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'playout'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function hasFallback(Channel $channel): bool
    {
        return $this->resolveFallbackFile($channel) !== null;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function launch(Channel $channel, array $cmd, string $mode): bool
    {
        $this->stop($channel);

        $pidFile = $this->ffmpeg->pidFile($channel, 'playout');
        $logFile = $this->ffmpeg->logFile($channel, 'playout');

        try {
            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);
        } catch (\Throwable $e) {
            Log::error("[Playout] {$channel->name} failed ({$mode}): {$e->getMessage()}");
            $channel->update(['playout_pid' => null, 'playout_status' => 'error']);
            return false;
        }

        $channel->update([
            'playout_pid'    => $pid,
            'playout_status' => $mode,
        ]);

        Log::info("[Playout] {$channel->name} started ({$mode}) — PID {$pid}");
        return true;
    }

    /**
     * Live: reads ingest live.m3u8 → re-packages to playout.m3u8
     * Stream copy, minimal latency, keeps a short window (10 segments).
     */
    private function buildLiveCommand(Channel $channel): array
    {
        $dvrDir     = $channel->dvr_directory;
        $segPattern = "{$dvrDir}/playout_%05d.ts";
        $m3u8Out    = "{$dvrDir}/playout.m3u8";
        $segDur     = max(1, (int) $channel->segment_duration);

        return [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-fflags',             '+genpts+igndts+discardcorrupt',
            '-live_start_index',   '0',
            '-allowed_extensions', 'ALL',
            '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
            '-timeout',            '10000000',
            '-i',                  "{$dvrDir}/live.m3u8",
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-f',                    'hls',
            '-hls_time',             (string) $segDur,
            '-hls_list_size',        '10',
            '-hls_flags',            'delete_segments+omit_endlist',
            '-hls_delete_threshold', '1',
            '-hls_segment_type',     'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache',      '0',
            $m3u8Out,
        ];
    }

    /**
     * Fallback: loops a recording file → writes to playout.m3u8
     * Seamless loop, stream copy.
     */
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
            '-hls_delete_threshold', '1',
            '-hls_segment_type',     'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache',      '0',
            $m3u8Out,
        ];
    }

    private function resolveFallbackFile(Channel $channel): ?string
    {
        if ($channel->fallback_recording_path && file_exists($channel->fallback_recording_path)
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
