<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Support\Facades\Log;

class PushService
{
    public function __construct(
        protected FFmpegService $ffmpeg,
        protected DVRService    $dvr,
    ) {}

    // ---------------------------------------------------------------
    // Live push (source → RTMP/SRT)
    // ---------------------------------------------------------------

    public function startLive(Channel $channel): bool
    {
        try {
            $cmd     = $this->ffmpeg->buildPushCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'push');
            $logFile = $this->ffmpeg->logFile($channel, 'push');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            if ($pid <= 0) {
                $logTail = $this->ffmpeg->readLogTail($logFile);
                throw new \RuntimeException("Push ffmpeg did not start. Log: {$logTail}");
            }

            $channel->update(['push_pid' => $pid, 'push_status' => 'pushing']);
            $this->log($channel, 'info', 'push_live_started', "Live push started (PID {$pid})", 'push');
            return true;

        } catch (\Throwable $e) {
            $channel->update(['push_status' => 'error']);
            $this->log($channel, 'error', 'push_live_failed', $e->getMessage(), 'push');
            Log::error("[Channel {$channel->id}] Live push failed: {$e->getMessage()}");
            return false;
        }
    }

    // ---------------------------------------------------------------
    // DVR playback push (concat → RTMP/SRT)
    // ---------------------------------------------------------------

    public function startDvrPlayback(Channel $channel): bool
    {
        try {
            if (!$this->dvr->buildConcatFile($channel)) {
                throw new \RuntimeException('No DVR segments available for playback');
            }

            $cmd     = $this->ffmpeg->buildDvrPlaybackCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'dvr');
            $logFile = $this->ffmpeg->logFile($channel, 'dvr');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            if ($pid <= 0) {
                $logTail = $this->ffmpeg->readLogTail($logFile);
                throw new \RuntimeException("DVR playback ffmpeg did not start. Log: {$logTail}");
            }

            $channel->update([
                'dvr_pid'       => $pid,
                'stream_status' => 'dvr_playback',
                'push_status'   => 'pushing',
                'dvr_status'    => 'playing',
            ]);

            $this->log($channel, 'info', 'dvr_playback_started', "DVR playback started (PID {$pid})", 'push');
            return true;

        } catch (\Throwable $e) {
            $channel->update([
                'dvr_status'  => 'error',
                'push_status' => 'error',
            ]);
            $this->log($channel, 'error', 'dvr_playback_failed', $e->getMessage(), 'push');
            Log::error("[Channel {$channel->id}] DVR playback failed: {$e->getMessage()}");
            return false;
        }
    }

    // ---------------------------------------------------------------
    // Stop all push processes
    // ---------------------------------------------------------------

    public function stopLive(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
            $this->log($channel, 'info', 'push_live_stopped', "Live push stopped (PID {$pid})", 'push');
        }

        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['push_pid' => null]);
    }

    public function stopDvrPlayback(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'dvr');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
            $this->log($channel, 'info', 'dvr_playback_stopped', "DVR playback stopped (PID {$pid})", 'push');
        }

        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['dvr_pid' => null]);
    }

    public function stopAll(Channel $channel): void
    {
        $this->stopLive($channel);
        $this->stopDvrPlayback($channel);
    }

    // ---------------------------------------------------------------
    // Health checks
    // ---------------------------------------------------------------

    public function isLiveRunning(Channel $channel): bool
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid     = $this->ffmpeg->readPid($pidFile);
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function isDvrRunning(Channel $channel): bool
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'dvr');
        $pid     = $this->ffmpeg->readPid($pidFile);
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    // ---------------------------------------------------------------
    // DVR playback restart check
    // ---------------------------------------------------------------

    public function dvrPlaybackNeedsRestart(Channel $channel): bool
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'dvr');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid <= 0) return true;

        $startTime = @filemtime($pidFile);
        if (!$startTime) return true;

        return (time() - $startTime) > $channel->dvr_duration * 0.5;
    }

    // ---------------------------------------------------------------
    // Logging
    // ---------------------------------------------------------------

    protected function log(Channel $channel, string $level, string $event, string $message, string $category = 'system', ?array $meta = null): void
    {
        try {
            StreamLog::create([
                'channel_id' => $channel->id,
                'level'      => $level,
                'event'      => $event,
                'message'    => $message,
                'metadata'   => array_merge(['category' => $category], $meta ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error("StreamLog write failed: {$e->getMessage()}");
        }
    }
}
