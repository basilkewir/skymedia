<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

class IngestService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    /**
     * Start a single ffmpeg process that simultaneously:
     * - Records DVR segments to disk
     * - Pushes live stream to the configured output
     */
    public function start(Channel $channel): bool
    {
        $this->stop($channel);

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            $cmd     = $this->ffmpeg->buildLiveCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
            $logFile = $this->ffmpeg->logFile($channel, 'ingest');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);
            if ($pid <= 0) return false;

            $channel->update([
                'pid'           => $pid,
                'push_pid'      => $pid, // same process handles both
                'dvr_status'    => 'recording',
                'push_status'   => 'pushing',
                'stream_status' => 'live',
                'is_active'     => true,
                'source_live'   => true,
                'last_live_at'  => now(),
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

        $channel->update([
            'pid'           => null,
            'push_pid'      => null,
            'dvr_status'    => 'idle',
            'push_status'   => 'idle',
            'stream_status' => 'stopped',
            'source_live'   => false,
            'is_active'     => false,
        ]);
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'ingest'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }
}
