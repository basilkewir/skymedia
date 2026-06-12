<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

/**
 * PushService — pushes the channel output to an external RTMP/SRT server.
 *
 * Reads whichever playlist PlayoutService says is current:
 *   live     → live.m3u8  (direct from ingest, no intermediate process)
 *   fallback → playout.m3u8 (from the fallback ffmpeg loop)
 */
class PushService
{
    public function __construct(
        protected FFmpegService $ffmpeg,
        protected PlayoutService $playout,
    ) {}

    public function start(Channel $channel): bool
    {
        $playlist = $this->playout->outputPlaylist($channel);

        if (!file_exists($playlist)) {
            Log::warning("[Push] {$channel->name}: playlist not ready ({$playlist})");
            return false;
        }

        $this->stop($channel);

        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $logFile = $this->ffmpeg->logFile($channel, 'push');

        try {
            $pid = $this->ffmpeg->startProcess(
                $this->ffmpeg->buildPushCommand($channel, $playlist),
                $pidFile,
                $logFile,
                8
            );
        } catch (\Throwable $e) {
            Log::error("[Push] {$channel->name} failed: {$e->getMessage()}");
            $channel->update(['push_pid' => null, 'push_status' => 'error']);
            return false;
        }

        $channel->update(['push_pid' => $pid, 'push_status' => 'live']);
        Log::info("[Push] {$channel->name} started — PID {$pid} — reading {$playlist}");
        return true;
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }

        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['push_pid' => null, 'push_status' => 'stopped']);
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'push'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }
}
