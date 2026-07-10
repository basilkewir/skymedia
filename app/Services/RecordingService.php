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
        if ($channel->isPushIngest()) {
            return false;
        }

        if ($channel->record_duration <= 0) {
            return false;
        }

        if ($this->isRunning($channel)) {
            return true;
        }

        $dvrDir = $channel->dvr_directory;
        $m3u8 = "{$dvrDir}/live.m3u8";

        if (! file_exists($m3u8)) {
            Log::debug("[Recording] {$channel->name}: live.m3u8 not yet available");

            return false;
        }

        $filename = 'rec_' . now($channel->timezone ?? 'UTC')->format('Ymd_His') . '.mp4';
        $filepath = "{$dvrDir}/{$filename}";

        // Create DB record so UI shows it immediately
        $recording = Recording::create([
            'channel_id' => $channel->id,
            'filepath' => $filepath,
            'filename' => $filename,
            'status' => 'recording',
            'filesize' => 0,
            'duration' => 0,
            'started_at' => now(),
        ]);

        $cmd = $this->buildCommand($channel, $m3u8, $filepath);
        $pidFile = $this->ffmpeg->pidFile($channel, 'record');
        $logFile = $this->ffmpeg->logFile($channel, 'record');

        try {
            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            $channel->update([
                'record_pid' => $pid,
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
        $pid = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            // Give recordings plenty of time to finalise the MP4 moov atom.
            // A SIGKILL before ffmpeg writes the trailer leaves an unplayable file.
            $this->ffmpeg->stopProcess($pid, 20);
        }

        $this->ffmpeg->clearPid($pidFile);

        // Finalize any in-progress recording — mark completed if file is usable,
        // delete if the file is missing, empty, or not playable (0-byte records).
        $recording = Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->latest('started_at')
            ->first();

        if ($recording) {
            $size = file_exists($recording->filepath) ? (int) filesize($recording->filepath) : 0;
            if ($size < 1024) {
                // Zero or near-zero byte file — delete it, don't keep garbage
                @unlink($recording->filepath);
                $recording->delete();
            } elseif ($this->ffmpeg->isPlayableFile($recording->filepath)) {
                $recording->update([
                    'status' => 'completed',
                    'filesize' => $size,
                    'completed_at' => now(),
                ]);
                $channel->update(['fallback_recording_path' => $recording->filepath]);
                if ($size > 0) {
                    $channel->increment('storage_used_bytes', $size);
                }
            } else {
                $recording->update(['status' => 'failed', 'completed_at' => now()]);
                @unlink($recording->filepath);
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
        if (! $channel->record_pid || $channel->record_status !== 'recording') {
            return;
        }

        $recording = Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->latest('started_at')
            ->first();

        if (! $recording) {
            return;
        }

        $size = file_exists($recording->filepath) ? (filesize($recording->filepath) ?: 0) : 0;
        $elapsed = (int) now()->diffInSeconds($recording->started_at);

        $recording->update(['filesize' => $size, 'duration' => $elapsed]);
    }

    public function justFinished(Channel $channel): bool
    {
        if (! $channel->record_pid || $channel->record_pid <= 0) {
            return false;
        }

        return ! $this->ffmpeg->isRunning((int) $channel->record_pid);
    }

    public function finish(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'record');
        $this->ffmpeg->clearPid($pidFile);

        $recording = Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->latest('started_at')
            ->first();

        if (! $recording) {
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

        // Immediately start the next recording segment so there is zero gap.
        // shouldRecord() will return true because record_pid is now null and
        // record_status is idle. The monitor tick also calls this, but doing
        // it here avoids waiting up to check_interval seconds for the next tick.
        if ($channel->record_duration > 0 && $channel->source_live) {
            $this->start($channel->fresh());
        }
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'record'));

        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    /**
     * Detect a stale recording: the ffmpeg process is running but the output
     * file hasn't grown in $staleSeconds. This happens when the source drops
     * and live.m3u8 stops getting new segments — ffmpeg hangs reading EOF.
     * Returns true if the recording is stale and should be stopped.
     */
    public function isStale(Channel $channel, int $staleSeconds = 60): bool
    {
        if (! $this->isRunning($channel)) {
            return false;
        }

        $recording = Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->latest('started_at')
            ->first();

        if (! $recording || ! file_exists($recording->filepath)) {
            return false;
        }

        $lastModified = filemtime($recording->filepath);
        if ($lastModified === false) {
            return false;
        }

        return (time() - $lastModified) >= $staleSeconds;
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
        // 0 = disabled; positive value = length of each recording file in seconds
        if ($channel->record_duration <= 0) {
            return false;
        }
        if ($channel->isPushIngest()) {
            return false;
        }
        // Only record when the source is actually live (not during fallback/VOD)
        if (! $channel->source_live || $channel->stream_status !== 'live') {
            return false;
        }
        if ($this->isRunning($channel)) {
            return false;
        }
        if ($channel->record_status === 'recording') {
            return false;
        }
        if (! $this->ffmpeg->hlsReady($channel, 2)) {
            return false;
        }
        if (! $this->hasEnoughDiskSpace($channel)) {
            return false;
        }

        return true;
    }

    /**
     * Abort an active recording if the disk is too full. Called every monitor tick.
     */
    public function abortIfDiskFull(Channel $channel): bool
    {
        if (! $this->isRunning($channel)) {
            return false;
        }

        if ($this->hasEnoughDiskSpace($channel)) {
            return false;
        }

        Log::error("[Recording] {$channel->name} stopped — disk space below safe threshold");
        $this->stop($channel);
        $channel->update(['last_error' => 'Recording stopped: insufficient disk space']);

        return true;
    }

    private function hasEnoughDiskSpace(Channel $channel): bool
    {
        $dvrDir = $channel->dvr_directory;
        if (! is_dir($dvrDir)) {
            mkdir($dvrDir, 0755, true);
        }

        $free = disk_free_space($dvrDir);
        if ($free === false) {
            Log::warning("[Recording] {$channel->name}: unable to determine free disk space");

            return false;
        }

        $minimum = (int) config('skymedia.min_free_disk_bytes', 5 * 1024 * 1024 * 1024);
        if ($free < $minimum) {
            Log::warning("[Recording] {$channel->name}: free disk space " . round($free / 1_073_741_824, 2) . ' GB is below minimum ' . round($minimum / 1_073_741_824, 2) . ' GB');

            return false;
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
                '-vf', 'drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf:'
                     . "text='%{pts\:gmtime\:{$channel->timezone}\:%Y-%m-%d %H\\\\:%M\\\\:%S}':"
                     . 'fontsize=18:fontcolor=white:box=1:boxcolor=black@0.5:'
                     . 'x=10:y=h-th-10',
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
        $minRetentionHours = max(1, (int) config('skymedia.min_recording_retention_hours', 24));
        $retentionCutoff = now()->subHours($minRetentionHours);

        // Delete and unlink old completed recordings beyond keep limit,
        // but never delete recordings that are still within the minimum
        // retention window (so fallback VOD is available for at least a day).
        Recording::where('channel_id', $channel->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->skip($keep)
            ->take(1000)
            ->get()
            ->each(function (Recording $rec) use ($channel, $retentionCutoff) {
                if ($rec->completed_at !== null && $rec->completed_at->gt($retentionCutoff)) {
                    return;
                }
                if ($rec->filepath !== $channel->fallback_recording_path) {
                    $size = filesize($rec->filepath) ?? 0;
                    @unlink($rec->filepath);
                    // Decrement channel storage usage
                    if ($size > 0) {
                        $channel->decrement('storage_used_bytes', $size);
                    }
                }
                $rec->delete();
            });

        // Clean up failed recordings older than the retention window.
        Recording::where('channel_id', $channel->id)
            ->where('status', 'failed')
            ->where('created_at', '<', $retentionCutoff)
            ->each(function (Recording $rec) {
                @unlink($rec->filepath);
                $rec->delete();
            });
    }
}
