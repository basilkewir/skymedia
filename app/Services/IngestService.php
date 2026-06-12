<?php

namespace App\Services;

use App\Models\Channel;

/**
 * IngestService — manages the ingest ffmpeg process.
 *
 * This process reads from the source (HLS/UDP/RTMP/SRT/MPEG-TS) and
 * writes HLS segments to disk:
 *
 *   source  →  ffmpeg ingest  →  seg_00000.ts … seg_NNNNN.ts  +  live.m3u8
 *
 * The live.m3u8 is what the PushService reads from in real-time.
 * hls_list_size 0 means ffmpeg never trims the playlist — DVRService handles
 * the rolling window by deleting old files and database records.
 */
class IngestService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    public function start(Channel $channel): bool
    {
        $this->stop($channel);

        $dvrDir = $channel->dvr_directory;
        if (!is_dir($dvrDir)) {
            mkdir($dvrDir, 0755, true);
        }

        $cmd     = $this->ffmpeg->buildIngestCommand($channel);
        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $logFile = $this->ffmpeg->logFile($channel, 'ingest');

        $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

        if ($pid <= 0) {
            return false;
        }

        $channel->update([
            'pid'           => $pid,
            'stream_status' => 'live',
            'dvr_status'    => 'recording',
            'source_live'   => true,
            'last_live_at'  => now(),
            'retry_count'   => 0,
            'last_error'    => null,
        ]);

        return true;
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
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
