<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

/**
 * PushService — pushes playout.m3u8 to an external RTMP/SRT server.
 *
 * This service has ONE job: read playout.m3u8 (produced by PlayoutService)
 * and push it to the configured destination.
 *
 * It knows nothing about live vs fallback — that is PlayoutService's concern.
 */
class PushService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    public function start(Channel $channel): bool
    {
        $playout = $channel->dvr_directory . '/playout.m3u8';

        if (!file_exists($playout)) {
            Log::warning("[Push] {$channel->name}: playout.m3u8 not ready yet");
            return false;
        }

        $this->stop($channel);

        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $logFile = $this->ffmpeg->logFile($channel, 'push');

        try {
            $pid = $this->ffmpeg->startProcess(
                $this->ffmpeg->buildPushCommand($channel),
                $pidFile,
                $logFile
            );
        } catch (\Throwable $e) {
            Log::error("[Push] {$channel->name} failed: {$e->getMessage()}");
            $channel->update(['push_pid' => null, 'push_status' => 'error']);
            return false;
        }

        $channel->update(['push_pid' => $pid, 'push_status' => 'live']);
        Log::info("[Push] {$channel->name} started — PID {$pid}");
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
