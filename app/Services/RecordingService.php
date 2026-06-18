<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\FinalizeRecording;
use App\Models\Channel;
use App\Models\Recording;
use Illuminate\Support\Facades\Log;

/**
 * RecordingService
 *
 * Records timed MP4 files by reading the ingest's live.m3u8 playlist
 * directly — the same HLS playlist that the push process reads.
 * Uses -live_start_index -1 to capture from the most recent segments,
 * and -t <record_duration> to limit the output length.
 * Produces self-contained MP4 files for fallback playback when
 * the source goes offline.
 */
class RecordingService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    public function start(Channel $channel): bool
    {
        if ($channel->record_duration <= 0) {
            return false;
        }

        if ($this->isRunning($channel)) {
            return true;
        }

        $dvrDir  = $channel->dvr_directory;
        $m3u8    = "{$dvrDir}/live.m3u8";

        if (!file_exists($m3u8)) {
            Log::debug("[Recording] {$channel->name}: live.m3u8 not yet available");
            return false;
        }

        $filename = 'rec_' . now($channel->timezone ?? 'UTC')->format('Ymd_His') . '.mp4';
        $filepath = "{$dvrDir}/{$filename}";

        // Create DB record so UI shows it immediately
        $recording = Recording::create([
            'channel_id'  => $channel->id,
            'filepath'    => $filepath,
            'filename'    => $filename,
            'status'      => 'recording',
            'filesize'    => 0,
            'duration'    => 0,
            'started_at'  => now(),
        ]);

        $cmd     = $this->buildCommand($channel, $m3u8, $filepath);
        $pidFile = $this->ffmpeg->pidFile($channel, 'record');
        $logFile = $this->ffmpeg->logFile($channel, 'record');

        try {
            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            $channel->update([
                'record_pid'    => $pid,
                'record_status' => 'recording',
            ]);

            Log::info("[Recording] {$channel->name} started — PID {$pid} → {$filename}");
            return true;

        } catch (\Throwable $e) {
            $recording->update(['status' => 'failed', 'completed_at' => now()]);
            $channel->update(['record_pid' => null, 'record_status' => 'idle']);
            Log::error("[Recording] {$channel->name} failed: {$e->getMessage()}");
            return false;
        }
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'record');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }

        $this->ffmpeg->clearPid($pidFile);

        // Finalize any in-progress recording — mark completed if file is usable,
        // failed only if the file is missing or empty.
        $recording = Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->latest('started_at')
            ->first();

        if ($recording) {
            if (file_exists($recording->filepath) && filesize($recording->filepath) > 1024) {
                $recording->update([
                    'status'       => 'completed',
                    'filesize'     => filesize($recording->filepath),
                    'completed_at' => now(),
                ]);
                // Always promote latest completed recording as fallback
                $channel->update(['fallback_recording_path' => $recording->filepath]);
            } else {
                $recording->update(['status' => 'failed', 'completed_at' => now()]);
            }
        }

        $channel->update(['record_pid' => null, 'record_status' => 'idle']);

        // Prune old VOD files to maintain disk space — use channel's retention policy
        $keep = max(1, (int) ($channel->keep_recordings ?? 3));
        $this->pruneOld($channel, keep: $keep);
    }

    /**
     * Called every monitor tick while recording is active.
     * Updates filesize + elapsed duration in DB so the UI shows live progress.
     */
    public function refreshProgress(Channel $channel): void
    {
        if (!$channel->record_pid || $channel->record_status !== 'recording') {
            return;
        }

        $recording = Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->latest('started_at')
            ->first();

        if (!$recording) {
            return;
        }

        $size    = file_exists($recording->filepath) ? (filesize($recording->filepath) ?: 0) : 0;
        $elapsed = (int) now()->diffInSeconds($recording->started_at);

        $recording->update(['filesize' => $size, 'duration' => $elapsed]);
    }

    public function justFinished(Channel $channel): bool
    {
        if (!$channel->record_pid || $channel->record_pid <= 0) {
            return false;
        }
        return !$this->ffmpeg->isRunning((int) $channel->record_pid);
    }

    public function finish(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'record');
        $this->ffmpeg->clearPid($pidFile);

        $recording = Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->latest('started_at')
            ->first();

        if (!$recording) {
            $channel->update(['record_pid' => null, 'record_status' => 'idle']);
            return;
        }

        $channel->update(['record_pid' => null, 'record_status' => 'idle']);

        // Dispatch async finalization job — handles probing, DB updates, VOD pruning
        FinalizeRecording::dispatch(
            $channel->id,
            $recording->id,
            $channel->timezone ?? 'UTC'
        );
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'record'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function hasFallback(Channel $channel): bool
    {
        if ($channel->fallback_recording_path && file_exists($channel->fallback_recording_path)) {
            return filesize($channel->fallback_recording_path) > 1024;
        }

        foreach (glob($channel->dvr_directory . '/rec_*.mp4') ?: [] as $f) {
            if (filesize($f) > 1024) {
                return true;
            }
        }

        return false;
    }

    public function shouldRecord(Channel $channel): bool
    {
        if ($this->isRunning($channel)) return false;
        if ($channel->record_status === 'recording') return false;
        if (!$this->ffmpeg->hlsReady($channel, 2)) return false;

        // Continuous mode: always record when live (splits into rolling files)
        if ($channel->record_duration <= 0) {
            return true;
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function buildCommand(Channel $channel, string $inputPath, string $output): array
    {
        // Segment duration: use record_duration if set, otherwise default to 1 hour
        $segmentDuration = $channel->record_duration > 0 ? $channel->record_duration : 3600;

        $cmd = [
            $this->ffmpeg->getBin(),
            '-y',
            '-loglevel',           'warning',
            '-stats',
            '-fflags',             '+genpts+igndts+discardcorrupt',
            '-live_start_index',   '-1',
            '-allowed_extensions', 'ALL',
            '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
            '-i',                  $inputPath,
        ];

        // Timecode burn-in overlay for fallback VODs
        if ($channel->recording_burn_timestamp) {
            $cmd = array_merge($cmd, [
                '-vf', "drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf:"
                     . "text='%{pts\:gmtime\:{$channel->timezone}\:%Y-%m-%d %H\\\\:%M\\\\:%S}':"
                     . "fontsize=18:fontcolor=white:box=1:boxcolor=black@0.5:"
                     . "x=10:y=h-th-10",
            ]);
        }

        $cmd = array_merge($cmd, [
            '-t',                  (string) $segmentDuration,
            '-c:v',                $channel->recording_burn_timestamp ? 'libx264' : 'copy',
            '-c:a',                $channel->recording_burn_timestamp ? 'aac' : 'copy',
            '-movflags',           '+faststart',
            '-avoid_negative_ts',  'make_zero',
            $output,
        ]);

        return $cmd;
    }

    private function pruneOld(Channel $channel, int $keep = 3): void
    {
        // Delete and unlink old completed recordings beyond keep limit
        Recording::where('channel_id', $channel->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->skip($keep)
            ->take(1000)
            ->get()
            ->each(function (Recording $rec) use ($channel) {
                if ($rec->filepath !== $channel->fallback_recording_path) {
                    @unlink($rec->filepath);
                }
                $rec->delete();
            });

        // Clean up failed recordings older than 1 hour (keep DB tidy)
        Recording::where('channel_id', $channel->id)
            ->where('status', 'failed')
            ->where('created_at', '<', now()->subHour())
            ->each(function (Recording $rec) {
                @unlink($rec->filepath);
                $rec->delete();
            });
    }
}
