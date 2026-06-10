<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

/**
 * IngestService manages the ingest ffmpeg process:
 *   source → HLS segments on disk (live.m3u8 + seg_NNNNN.ts)
 *
 * This process is responsible solely for DVR recording.
 * It does NOT start, stop, or affect the push process in any way.
 */
class IngestService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    public function start(Channel $channel): bool
    {
        $this->stop($channel);

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            $cmd     = $this->ffmpeg->buildIngestCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
            $logFile = $this->ffmpeg->logFile($channel, 'ingest');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);
            if ($pid <= 0) return false;

            $channel->update([
                'pid'          => $pid,
                'dvr_status'   => 'recording',
                'stream_status'=> 'live',
                'is_active'    => true,
                'source_live'  => true,
                'last_live_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("[Ingest:{$channel->id}] {$e->getMessage()}");
            return false;
        }
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) $this->ffmpeg->stopProcess($pid);
        $this->ffmpeg->clearPid($pidFile);

        // Only clear ingest-owned fields — never touch push_pid or push_status
        $channel->update([
            'pid'         => null,
            'dvr_status'  => 'idle',
            'source_live' => false,
        ]);
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'ingest'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }
}
