<?php

namespace App\Services;

use App\Models\Channel;

/**
 * PushService — manages the push ffmpeg process.
 *
 * THREE modes:
 *
 *  LIVE      — reads live.m3u8 (always the last N segments from ingest)
 *              When source is live, this tracks ~segment_duration behind live.
 *
 *  DVR LOOP  — reads concat.txt (all stored segments, looped)
 *              Used when operator manually forces a loop of stored content.
 *
 *  FALLBACK  — loops the latest completed recording .mp4 file indefinitely.
 *              Automatically activated by the monitor when source goes offline.
 *              Output NEVER goes dark as long as a recording exists.
 *
 * The push process applies encoding settings from the channel:
 *   push_video_codec, push_video_bitrate, push_resolution, push_framerate
 *   push_audio_codec, push_audio_bitrate, push_audio_samplerate, push_audio_channels
 */
class PushService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    // ── Live push: reads from live.m3u8 ─────────────────────────────────────

    public function startLive(Channel $channel): bool
    {
        $m3u8 = $channel->dvr_directory . '/live.m3u8';

        // Wait up to 10 s for HLS to be ready
        $waited = 0;
        while (!$this->ffmpeg->hlsReady($channel, 2) && $waited < 10) {
            sleep(1);
            $waited++;
        }

        if (!file_exists($m3u8)) {
            return false;
        }

        return $this->launch($channel, $this->ffmpeg->buildPushCommand($channel), 'live');
    }

    // ── DVR loop: reads from concat.txt ─────────────────────────────────────

    public function startDvrPlayback(Channel $channel): bool
    {
        return $this->launch($channel, $this->ffmpeg->buildDvrPlaybackCommand($channel), 'dvr');
    }

    // ── Fallback: loops the latest completed recording .mp4 ─────────────────

    public function startRecordingFallback(Channel $channel): bool
    {
        $file = $channel->fallback_recording_path;

        if (!$file || !file_exists($file)) {
            // Try to find the latest completed recording from disk
            $files = glob($channel->dvr_directory . '/rec_*.mp4') ?: [];
            if (empty($files)) {
                return false;
            }
            usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
            $file = $files[0];
        }

        return $this->launch($channel, $this->ffmpeg->buildRecordingFallbackCommand($channel, $file), 'fallback');
    }

    // ── Stop push ────────────────────────────────────────────────────────────

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

    // ── State ────────────────────────────────────────────────────────────────

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'push'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private function launch(Channel $channel, array $cmd, string $mode): bool
    {
        $this->stop($channel);

        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $logFile = $this->ffmpeg->logFile($channel, 'push');

        $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

        if ($pid <= 0) {
            $channel->update(['push_status' => 'error']);
            return false;
        }

        $statusMap = [
            'live'     => 'live',
            'dvr'      => 'fallback',
            'fallback' => 'fallback',
        ];

        $channel->update([
            'push_pid'    => $pid,
            'push_status' => $statusMap[$mode] ?? 'live',
        ]);

        return true;
    }
}
