<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

class PushService
{
    public function __construct(
        protected FFmpegService $ffmpeg,
        protected DVRService    $dvr,
    ) {}

    // ── Live push (reads from DVR segments via ingest) ──────────────

    public function startLive(Channel $channel): bool
    {
        $this->stopLive($channel);

        try {
            $cmd     = $this->ffmpeg->buildPushCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'push');
            $logFile = $this->ffmpeg->logFile($channel, 'push');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);
            if ($pid <= 0) return false;

            $channel->update(['push_pid' => $pid, 'push_status' => 'pushing']);
            return true;
        } catch (\Throwable $e) {
            Log::error("[Push:{$channel->id}] {$e->getMessage()}");
            return false;
        }
    }

    public function stopLive(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) $this->ffmpeg->stopProcess($pid);
        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['push_pid' => null, 'push_status' => 'idle']);
    }

    public function isLiveRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'push'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    // ── DVR playback push (loops DVR segments to output) ────────────

    public function startDvrPlayback(Channel $channel): bool
    {
        $this->stopDvrPlayback($channel);

        try {
            if (!$this->dvr->buildConcatFile($channel)) return false;

            $cmd     = $this->ffmpeg->buildDvrPlaybackCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'dvr_push');
            $logFile = $this->ffmpeg->logFile($channel, 'dvr_push');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);
            if ($pid <= 0) return false;

            $channel->update([
                'dvr_pid'       => $pid,
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
        $pidFile = $this->ffmpeg->pidFile($channel, 'dvr_push');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) $this->ffmpeg->stopProcess($pid);
        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['dvr_pid' => null]);
    }

    public function isDvrRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'dvr_push'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function dvrPlaybackNeedsRestart(Channel $channel): bool
    {
        // Restart DVR playback every 60s to pick up new segments
        return $channel->updated_at?->diffInSeconds(now()) > 60;
    }

    public function stopAll(Channel $channel): void
    {
        $this->stopLive($channel);
        $this->stopDvrPlayback($channel);
    }
}
