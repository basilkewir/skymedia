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

    // ── Live push is handled by IngestService's tee command ─────────
    // These methods manage the push status without spawning a new process.

    public function startLive(Channel $channel): bool
    {
        // Live push is embedded in the ingest tee process.
        // Just update status — the ingest process handles output.
        $channel->update(['push_status' => 'pushing']);
        return true;
    }

    public function stopLive(Channel $channel): void
    {
        $channel->update(['push_pid' => null, 'push_status' => 'idle']);
    }

    public function isLiveRunning(Channel $channel): bool
    {
        // Live push runs inside the ingest process
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'ingest'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    // ── DVR playback push (loops DVR segments to output) ────────────
    // This DOES need a separate ffmpeg process since there is no live ingest.

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
        $pidFile = $this->ffmpeg->pidFile($channel, 'dvr_push');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) $this->ffmpeg->stopProcess($pid);
        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['dvr_pid' => null, 'push_pid' => null, 'push_status' => 'idle']);
    }

    public function isDvrRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'dvr_push'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function dvrPlaybackNeedsRestart(Channel $channel): bool
    {
        return $channel->updated_at?->diffInSeconds(now()) > 60;
    }

    public function stopAll(Channel $channel): void
    {
        $this->stopLive($channel);
        $this->stopDvrPlayback($channel);
    }
}
