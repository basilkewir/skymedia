<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

class IngestService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    /**
     * Start the ingest ffmpeg process.
     *
     * @throws \RuntimeException with the ffmpeg stderr on failure
     */
    public function start(Channel $channel): bool
    {
        $this->stop($channel);

        $dvrDir = $channel->dvr_directory;
        if (!is_dir($dvrDir)) {
            if (!mkdir($dvrDir, 0755, true) && !is_dir($dvrDir)) {
                throw new \RuntimeException("Cannot create DVR directory: {$dvrDir}");
            }
        }

        // Verify ffmpeg binary exists and is executable
        $bin     = $this->ffmpeg->getBin();
        $binPath = trim((string) shell_exec("which {$bin} 2>/dev/null"))
                   ?: trim((string) shell_exec("command -v {$bin} 2>/dev/null"));

        if (empty($binPath)) {
            throw new \RuntimeException(
                "ffmpeg binary not found in PATH. Configured as: '{$bin}'. "
                . "Run: which ffmpeg   or set FFMPEG_BINARY=/full/path in .env"
            );
        }

        $cmd     = $this->ffmpeg->buildIngestCommand($channel);
        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $logFile = $this->ffmpeg->logFile($channel, 'ingest');

        // startProcess throws \RuntimeException with ffmpeg output on failure
        $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

        $channel->update([
            'pid'           => $pid,
            'stream_status' => 'live',
            'dvr_status'    => 'recording',
            'source_live'   => true,
            'last_live_at'  => now(),
            'retry_count'   => 0,
            'last_error'    => null,
        ]);

        Log::info("[Ingest] {$channel->name} started — PID {$pid} — DVR: {$dvrDir}");
        return true;
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
            Log::info("[Ingest] {$channel->name} stopped — PID {$pid}");
        }

        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['pid' => null]);
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'ingest'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }
}
