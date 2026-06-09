<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Support\Facades\Log;

class IngestService
{
    public function __construct(
        protected FFmpegService $ffmpeg,
    ) {}

    public function start(Channel $channel): bool
    {
        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            $cmd     = $this->ffmpeg->buildDvrRecordCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'live');
            $logFile = $this->ffmpeg->logFile($channel, 'live');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            if ($pid <= 0) {
                $logTail = $this->ffmpeg->readLogTail($logFile);
                throw new \RuntimeException("Ingest ffmpeg did not start. Log: {$logTail}");
            }

            $channel->update([
                'pid'          => $pid,
                'dvr_status'   => 'recording',
                'stream_status'=> 'live',
                'source_live'  => true,
            ]);

            $this->log($channel, 'info', 'ingest_started', "Ingest started (PID {$pid})", 'source');
            return true;

        } catch (\Throwable $e) {
            $channel->update(['dvr_status' => 'error']);
            $this->log($channel, 'error', 'ingest_failed', $e->getMessage(), 'source');
            Log::error("[Channel {$channel->id}] Ingest failed: {$e->getMessage()}");
            return false;
        }
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'live');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
            $this->log($channel, 'info', 'ingest_stopped', "Ingest stopped (PID {$pid})", 'source');
        }

        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['pid' => null]);
    }

    public function isRunning(Channel $channel): bool
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'live');
        $pid     = $this->ffmpeg->readPid($pidFile);
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function isRecording(Channel $channel): bool
    {
        return $this->isRunning($channel);
    }

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
