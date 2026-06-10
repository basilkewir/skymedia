<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

class PushService
{
    // How many seconds to wait for live.m3u8 + minimum segments before starting push
    private const HLS_READY_WAIT_SEC  = 10;
    private const HLS_MIN_SEGMENTS    = 2;

    // How long to wait before refreshing a looping DVR push with new segments
    private const DVR_REFRESH_SEC     = 60;

    public function __construct(
        protected FFmpegService $ffmpeg,
        protected DVRService    $dvr,
    ) {}

    // ===================================================================
    //  START — always reads from DVR HLS (live or offline)
    // ===================================================================

    /**
     * Start the push process reading from the local DVR HLS playlist.
     *
     * When the source is live, live.m3u8 is being updated by the ingest
     * process and the push stays near-live.
     *
     * When the source is offline, push reads whatever segments exist in
     * live.m3u8, looping them via the concat fallback if needed.
     */
    public function start(Channel $channel, bool $waitForHls = true): bool
    {
        $this->stop($channel);

        try {
            if ($waitForHls) {
                $this->waitForHlsReady($channel);
            }

            // Prefer live.m3u8 (ingest is/was running); fall back to concat
            $m3u8 = $channel->dvr_directory . '/live.m3u8';

            if (file_exists($m3u8)) {
                $cmd = $this->ffmpeg->buildPushCommand($channel);
            } elseif ($this->dvr->buildConcatFile($channel)) {
                $cmd = $this->ffmpeg->buildDvrPlaybackCommand($channel);
            } else {
                Log::warning("[Push:{$channel->id}] No HLS or DVR segments available.");
                return false;
            }

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
            Log::error("[Push:{$channel->id}] {$e->getMessage()}");
            return false;
        }
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) $this->ffmpeg->stopProcess($pid);
        $this->ffmpeg->clearPid($pidFile);

        $channel->update(['push_pid' => null, 'push_status' => 'idle']);
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'push'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    // ===================================================================
    //  DVR LOOPING PUSH — used when source is offline
    // ===================================================================

    /**
     * Start a looping DVR push using the concat file.
     * Called by StreamManager when source goes offline.
     */
    public function startDvrPlayback(Channel $channel): bool
    {
        $this->stop($channel);

        try {
            if (!$this->dvr->buildConcatFile($channel)) return false;

            $cmd     = $this->ffmpeg->buildDvrPlaybackCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'push');
            $logFile = $this->ffmpeg->logFile($channel, 'push');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);
            if ($pid <= 0) return false;

            $channel->update([
                'dvr_pid'       => $pid,
                'push_pid'      => $pid,
                'stream_status' => 'dvr_playback',
                'dvr_status'    => 'playing',
                'push_status'   => 'pushing',
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("[DvrPush:{$channel->id}] {$e->getMessage()}");
            return false;
        }
    }

    public function stopDvrPlayback(Channel $channel): void
    {
        // Same PID file as regular push
        $this->stop($channel);
        $channel->update(['dvr_pid' => null]);
    }

    public function isDvrRunning(Channel $channel): bool
    {
        return $this->isRunning($channel);
    }

    /**
     * Returns true when the DVR push has been looping long enough that
     * we should rebuild concat.txt and restart with fresher segments.
     */
    public function dvrPlaybackNeedsRefresh(Channel $channel): bool
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        if (!file_exists($pidFile)) return false;

        $age = time() - filemtime($pidFile);
        return $age >= self::DVR_REFRESH_SEC;
    }

    // ===================================================================
    //  LEGACY COMPAT (called by StreamManager)
    // ===================================================================

    public function startLive(Channel $channel): bool
    {
        return $this->start($channel, waitForHls: true);
    }

    public function stopLive(Channel $channel): void
    {
        $this->stop($channel);
    }

    public function isLiveRunning(Channel $channel): bool
    {
        return $this->isRunning($channel);
    }

    public function stopAll(Channel $channel): void
    {
        $this->stop($channel);
        $channel->update(['dvr_pid' => null]);
    }

    /** @deprecated use dvrPlaybackNeedsRefresh */
    public function dvrPlaybackNeedsRestart(Channel $channel): bool
    {
        return $this->dvrPlaybackNeedsRefresh($channel);
    }

    // ===================================================================
    //  INTERNAL
    // ===================================================================

    private function waitForHlsReady(Channel $channel): void
    {
        $waited = 0;
        while (!$this->ffmpeg->hlsReady($channel, self::HLS_MIN_SEGMENTS) && $waited < self::HLS_READY_WAIT_SEC) {
            sleep(1);
            $waited++;
        }
    }
}
