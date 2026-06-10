<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Recording;
use Illuminate\Support\Facades\Log;

/**
 * RecordingService manages timed channel recordings.
 *
 * Behaviour:
 *  - When ingest goes live and record_duration > 0, start a timed recording.
 *  - ffmpeg records for exactly record_duration seconds then exits naturally.
 *  - On completion the monitor calls finishRecording():
 *      • verifies the output file exists and has size > 0
 *      • only then deletes the previous completed recording
 *      • promotes the new file as the channel's fallback_recording_path
 *  - If the recording failed (process died early or file is empty) the old
 *    fallback file is preserved untouched.
 *  - A daily re-record is triggered by the monitor whenever:
 *      • no recording is in progress AND
 *      • record_duration > 0 AND
 *      • source is live AND
 *      • the last completed recording is older than record_duration seconds
 *        (i.e. it is time for a fresh daily/timed recording)
 */
class RecordingService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    // ===================================================================
    //  START
    // ===================================================================

    /**
     * Start a timed recording for record_duration seconds.
     * The output file is written to recordings/ inside the DVR directory.
     * Returns true if the ffmpeg process started successfully.
     */
    public function start(Channel $channel): bool
    {
        if ($channel->record_duration <= 0) return false;
        if ($this->isRunning($channel)) return true; // already recording

        $dir = $this->recordingsDir($channel);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        // Temporary file — renamed to final name on successful completion
        $tmpFile   = "{$dir}/rec_tmp_{$channel->id}.mp4";
        $pidFile   = $this->ffmpeg->pidFile($channel, 'record');
        $logFile   = $this->ffmpeg->logFile($channel, 'record');

        // Clean up any stale tmp file from a previous crashed run
        @unlink($tmpFile);

        $cmd = $this->buildRecordCommand($channel, $tmpFile);
        $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

        if ($pid <= 0) {
            Log::error("[Record:{$channel->id}] Failed to start recording process");
            $channel->update(['record_status' => 'error']);
            return false;
        }

        // Create DB record
        Recording::create([
            'channel_id' => $channel->id,
            'filepath'   => $tmpFile,
            'filesize'   => 0,
            'duration'   => 0,
            'status'     => 'recording',
            'started_at' => now(),
        ]);

        $channel->update([
            'record_pid'    => $pid,
            'record_status' => 'recording',
        ]);

        Log::info("[Record:{$channel->id}] Recording started — PID {$pid} — duration {$channel->record_duration}s → {$tmpFile}");
        return true;
    }

    // ===================================================================
    //  STOP (manual or called on ingest stop)
    // ===================================================================

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'record');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) $this->ffmpeg->stopProcess($pid);
        $this->ffmpeg->clearPid($pidFile);

        $channel->update(['record_pid' => null, 'record_status' => 'idle']);

        // Mark any in-progress DB recording as failed
        Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->update(['status' => 'failed', 'completed_at' => now(), 'error' => 'Stopped manually']);
    }

    // ===================================================================
    //  FINISH — called by monitor when record process exits naturally
    // ===================================================================

    /**
     * Called when the recording ffmpeg process exits (PID gone).
     *
     * 1. Finds the tmp file written during this recording session
     * 2. Validates it is not empty
     * 3. Renames it to a timestamped final filename
     * 4. Deletes the PREVIOUS completed recording (old file + old DB row)
     * 5. Updates the channel's fallback_recording_path
     * 6. Marks record_status = idle (ready for next recording)
     */
    public function finish(Channel $channel): void
    {
        $this->ffmpeg->clearPid($this->ffmpeg->pidFile($channel, 'record'));

        $dir     = $this->recordingsDir($channel);
        $tmpFile = "{$dir}/rec_tmp_{$channel->id}.mp4";

        // Find the DB row for this recording
        $recording = Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->latest('started_at')
            ->first();

        // Validate the output file
        if (!file_exists($tmpFile) || filesize($tmpFile) < 1024) {
            Log::warning("[Record:{$channel->id}] Recording finished but output file missing or empty — keeping old fallback");
            if ($recording) {
                $recording->update(['status' => 'failed', 'completed_at' => now(), 'error' => 'Output file empty or missing']);
            }
            $channel->update(['record_pid' => null, 'record_status' => 'idle']);
            return;
        }

        // Probe actual duration from the file
        $duration = $this->probeDuration($tmpFile) ?? $channel->record_duration;
        $filesize = filesize($tmpFile);

        // Rename to final timestamped filename
        $finalFile = "{$dir}/rec_" . now()->format('Ymd_His') . "_{$channel->id}.mp4";
        rename($tmpFile, $finalFile);

        // Update DB row
        if ($recording) {
            $recording->update([
                'filepath'     => $finalFile,
                'filesize'     => $filesize,
                'duration'     => $duration,
                'status'       => 'completed',
                'completed_at' => now(),
            ]);
        } else {
            Recording::create([
                'channel_id'   => $channel->id,
                'filepath'     => $finalFile,
                'filesize'     => $filesize,
                'duration'     => $duration,
                'status'       => 'completed',
                'started_at'   => now()->subSeconds((int) $duration),
                'completed_at' => now(),
            ]);
        }

        // ── Atomic swap: delete old ONLY after new file is confirmed good ──
        $previousPath = $channel->fallback_recording_path;
        $channel->update([
            'fallback_recording_path' => $finalFile,
            'record_pid'              => null,
            'record_status'           => 'idle',
        ]);

        if ($previousPath && $previousPath !== $finalFile && file_exists($previousPath)) {
            @unlink($previousPath);
            Log::info("[Record:{$channel->id}] Deleted old recording: {$previousPath}");
            // Remove from DB too
            Recording::where('channel_id', $channel->id)
                ->where('filepath', $previousPath)
                ->delete();
        }

        Log::info("[Record:{$channel->id}] Recording completed → {$finalFile} ({$filesize} bytes, {$duration}s)");
    }

    // ===================================================================
    //  STATUS
    // ===================================================================

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'record'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    /**
     * True when it is time to start a new timed recording.
     * Conditions:
     *  - record_duration > 0
     *  - source is live
     *  - no recording currently in progress
     *  - no completed recording exists, OR the last completed recording is
     *    older than record_duration seconds (time for a fresh one)
     */
    public function shouldRecord(Channel $channel): bool
    {
        if ($channel->record_duration <= 0) return false;
        if (!$channel->source_live) return false;
        if ($this->isRunning($channel)) return false;

        $last = Recording::where('channel_id', $channel->id)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        if (!$last) return true;

        // Re-record when the last recording is at least record_duration seconds old
        return $last->completed_at->addSeconds($channel->record_duration)->isPast();
    }

    /**
     * True when the recording process just finished (PID gone but status = recording in DB).
     */
    public function justFinished(Channel $channel): bool
    {
        if ($channel->record_status !== 'recording') return false;
        return !$this->isRunning($channel);
    }

    public function hasFallback(Channel $channel): bool
    {
        return !empty($channel->fallback_recording_path)
            && file_exists($channel->fallback_recording_path)
            && filesize($channel->fallback_recording_path) > 0;
    }

    // ===================================================================
    //  COMMAND BUILDER
    // ===================================================================

    /**
     * Build the ffmpeg command to record the source for exactly
     * record_duration seconds into a single MP4 file.
     *
     * Uses -t to hard-stop after record_duration seconds.
     * Always copies streams (no re-encode) for reliability and speed.
     * movflags +faststart ensures the file is playable immediately.
     */
    private function buildRecordCommand(Channel $channel, string $outFile): array
    {
        $ffmpegBin  = config('skymedia.ffmpeg_binary', 'ffmpeg');
        $probesize  = in_array($channel->source_type, ['udp', 'mpegts']) ? '5000000' : '1000000';
        $analyze    = in_array($channel->source_type, ['udp', 'mpegts']) ? '3000000' : '1000000';

        $inputFlags = match ($channel->source_type) {
            'udp', 'mpegts' => [
                '-fflags', '+genpts+discardcorrupt',
                '-probesize', $probesize, '-analyzeduration', $analyze,
                '-timeout', '10000000',
                '-i', $channel->source_url,
            ],
            'hls' => [
                '-re',
                '-fflags', '+genpts',
                '-probesize', $probesize, '-analyzeduration', $analyze,
                '-allowed_extensions', 'ALL',
                '-timeout', '15000000',
                '-i', $channel->source_url,
            ],
            'srt' => [
                '-fflags', '+genpts+discardcorrupt',
                '-probesize', $probesize, '-analyzeduration', $analyze,
                '-i', 'srt://' . preg_replace('#^srt://#', '', $channel->source_url)
                    . '?timeout=8000000&latency=' . (config('skymedia.srt_latency', 200) * 1000),
            ],
            default => [
                '-fflags', '+genpts+discardcorrupt',
                '-probesize', $probesize, '-analyzeduration', $analyze,
                '-timeout', '10000000',
                '-i', $channel->source_url,
            ],
        };

        return array_merge(
            [$ffmpegBin, '-y', '-loglevel', 'warning'],
            $inputFlags,
            [
                '-t',         (string) $channel->record_duration,
                '-c:v',       'copy',
                '-c:a',       'copy',
                '-movflags',  '+faststart',
                '-f',         'mp4',
                $outFile,
            ]
        );
    }

    // ===================================================================
    //  HELPERS
    // ===================================================================

    public function recordingsDir(Channel $channel): string
    {
        return rtrim($channel->dvr_directory, '/') . '/recordings';
    }

    private function probeDuration(string $filepath): ?float
    {
        try {
            $proc = new \Symfony\Component\Process\Process([
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'quiet', '-print_format', 'json', '-show_format', $filepath,
            ]);
            $proc->setTimeout(5);
            $proc->run();
            if (!$proc->isSuccessful()) return null;
            $data = json_decode($proc->getOutput(), true);
            $dur  = $data['format']['duration'] ?? null;
            return $dur ? (float) $dur : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
