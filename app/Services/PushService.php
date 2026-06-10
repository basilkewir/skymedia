<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

/**
 * PushService manages the push ffmpeg process:
 *   DVR HLS → encode → RTMP/SRT output
 *
 * Push is ALWAYS manual. The monitor never calls any method here.
 * Two modes:
 *   live  — reads live.m3u8 (near-live, updated by ingest)
 *   dvr   — reads concat.txt (loops stored segments)
 */
class PushService
{
    // Minimum segments before allowing a live push to start
    private const HLS_MIN_SEGMENTS   = 2;
    // Max seconds to wait for HLS to be ready before giving up
    private const HLS_READY_WAIT_SEC = 12;

    public function __construct(
        protected FFmpegService $ffmpeg,
        protected DVRService    $dvr,
    ) {}

    // ===================================================================
    //  LIVE PUSH — reads live.m3u8 (near-live from ingest)
    // ===================================================================

    /**
     * Start push reading from the live DVR HLS playlist.
     * Waits up to HLS_READY_WAIT_SEC for segments to appear.
     */
    public function startLive(Channel $channel): bool
    {
        $this->stop($channel);

        $m3u8 = $channel->dvr_directory . '/live.m3u8';

        if (!$this->waitForHlsReady($channel)) {
            // live.m3u8 not ready — no ingest running
            Log::warning("[Push:{$channel->id}] live.m3u8 not ready — start ingest first");
            return false;
        }

        try {
            $cmd     = $this->ffmpeg->buildPushCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'push');
            $logFile = $this->ffmpeg->logFile($channel, 'push');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);
            if ($pid <= 0) return false;

            $channel->update([
                'push_pid'    => $pid,
                'push_status' => 'pushing',
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("[Push:{$channel->id}] startLive: {$e->getMessage()}");
            return false;
        }
    }

    // ===================================================================
    //  DVR LOOP PUSH — reads concat.txt (loops stored segments)
    // ===================================================================

    /**
     * Start push looping all available DVR segments via concat demuxer.
     * Works even when ingest is not running.
     */
    public function startDvrPlayback(Channel $channel): bool
    {
        $this->stop($channel);

        if (!$this->dvr->buildConcatFile($channel)) {
            Log::warning("[Push:{$channel->id}] No DVR segments for playback");
            return false;
        }

        try {
            $cmd     = $this->ffmpeg->buildDvrPlaybackCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'push');
            $logFile = $this->ffmpeg->logFile($channel, 'push');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);
            if ($pid <= 0) return false;

            $channel->update([
                'push_pid'    => $pid,
                'push_status' => 'pushing',
                'dvr_status'  => 'playing',
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("[Push:{$channel->id}] startDvrPlayback: {$e->getMessage()}");
            return false;
        }
    }

    // ===================================================================
    //  RECORDING FALLBACK PUSH — loops completed MP4 recording file
    // ===================================================================

    /**
     * Start push looping the channel's last completed MP4 recording.
     * Called automatically by monitor when source goes offline.
     * Never called manually — that is what startDvrPlayback is for.
     */
    public function startRecordingFallback(Channel $channel): bool
    {
        $file = $channel->fallback_recording_path;

        if (empty($file) || !file_exists($file) || filesize($file) < 1024) {
            Log::warning("[Push:{$channel->id}] No valid recording fallback file");
            return false;
        }

        $this->stop($channel);

        try {
            $cmd     = $this->ffmpeg->buildRecordingFallbackCommand($channel, $file);
            $pidFile = $this->ffmpeg->pidFile($channel, 'push');
            $logFile = $this->ffmpeg->logFile($channel, 'push');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);
            if ($pid <= 0) return false;

            $channel->update([
                'push_pid'      => $pid,
                'push_status'   => 'pushing',
                'stream_status' => 'fallback',
            ]);

            Log::info("[Push:{$channel->id}] Recording fallback started — PID {$pid} — file: {$file}");
            return true;
        } catch (\Throwable $e) {
            Log::error("[Push:{$channel->id}] startRecordingFallback: {$e->getMessage()}");
            return false;
        }
    }

    // ===================================================================
    //  STOP
    // ===================================================================

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) $this->ffmpeg->stopProcess($pid);
        $this->ffmpeg->clearPid($pidFile);

        $channel->update(['push_pid' => null, 'push_status' => 'idle']);

        // If DVR was in 'playing' state, revert it to 'idle' unless ingest is running
        if ($channel->dvr_status === 'playing') {
            $channel->update(['dvr_status' => 'idle']);
        }
    }

    // ===================================================================
    //  STATUS
    // ===================================================================

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'push'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    // ===================================================================
    //  COMPAT ALIASES
    // ===================================================================

    public function start(Channel $channel, bool $waitForHls = true): bool
    {
        return $this->startLive($channel);
    }

    public function stopAll(Channel $channel): void
    {
        $this->stop($channel);
    }

    public function isLiveRunning(Channel $channel): bool
    {
        return $this->isRunning($channel);
    }

    public function isDvrRunning(Channel $channel): bool
    {
        return $this->isRunning($channel);
    }

    // ===================================================================
    //  INTERNAL
    // ===================================================================

    private function waitForHlsReady(Channel $channel): bool
    {
        $waited = 0;
        while (!$this->ffmpeg->hlsReady($channel, self::HLS_MIN_SEGMENTS) && $waited < self::HLS_READY_WAIT_SEC) {
            sleep(1);
            $waited++;
        }
        return $this->ffmpeg->hlsReady($channel, self::HLS_MIN_SEGMENTS);
    }
}
