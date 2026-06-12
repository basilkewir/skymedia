<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Recording;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * RecordingService — produces timed MP4 recordings from the live HLS buffer.
 *
 * HOW IT WORKS:
 *   1. When ingest starts, a recording process is launched immediately.
 *   2. The recording runs for exactly record_duration seconds, then exits.
 *   3. The monitor calls justFinished() each tick; when true it calls finish()
 *      which: atomically marks the file as completed, updates
 *      fallback_recording_path on the channel, and logs the event.
 *   4. shouldRecord() returns true immediately after finish() so the next
 *      recording starts without a gap.
 *   5. Old recordings beyond 3 are pruned to save disk space.
 *
 * WHY MP4 NOT HLS:
 *   A self-contained MP4 is reliable for infinite looping (-stream_loop -1).
 *   The push process can loop it without any gap or sync issues.
 */
class RecordingService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    // ── Start a new recording ────────────────────────────────────────────────

    public function start(Channel $channel): bool
    {
        if ($channel->record_duration <= 0) {
            return false;
        }

        // Don't double-start
        if ($this->isRunning($channel)) {
            return true;
        }

        $dvrDir  = $channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

        $filename   = 'rec_' . now()->format('Ymd_His') . '.mp4';
        $filepath   = "{$dvrDir}/{$filename}";
        $m3u8       = "{$dvrDir}/live.m3u8";

        if (!file_exists($m3u8)) {
            Log::debug("[Recording] {$channel->name}: live.m3u8 not ready yet");
            return false;
        }

        // Create DB record immediately
        $recording = Recording::create([
            'channel_id'  => $channel->id,
            'filepath'    => $filepath,
            'filename'    => $filename,
            'status'      => 'recording',
            'started_at'  => now(),
        ]);

        $cmd = $this->buildRecordCommand($channel, $m3u8, $filepath);
        $pidFile = $this->ffmpeg->pidFile($channel, 'record');
        $logFile = $this->ffmpeg->logFile($channel, 'record');

        $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

        if ($pid <= 0) {
            $recording->update(['status' => 'failed']);
            return false;
        }

        $channel->update([
            'record_pid'    => $pid,
            'record_status' => 'recording',
        ]);

        return true;
    }

    // ── Stop recording gracefully ────────────────────────────────────────────

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'record');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }

        $this->ffmpeg->clearPid($pidFile);

        // Mark any in-progress recording as failed
        Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->update(['status' => 'failed', 'completed_at' => now()]);

        $channel->update(['record_pid' => null, 'record_status' => 'idle']);
    }

    // ── Detect natural completion (process exited) ───────────────────────────

    public function justFinished(Channel $channel): bool
    {
        if (!$channel->record_pid) return false;
        return !$this->ffmpeg->isRunning($channel->record_pid);
    }

    // ── Finalize a completed recording ──────────────────────────────────────

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

        $filepath = $recording->filepath;

        if (file_exists($filepath) && filesize($filepath) > 0) {
            // Probe actual duration
            $duration = $this->probeDuration($filepath) ?? (float) $channel->record_duration;

            $recording->update([
                'status'       => 'completed',
                'duration'     => $duration,
                'filesize'     => filesize($filepath),
                'completed_at' => now(),
            ]);

            // Set as the active fallback
            $channel->update([
                'record_pid'              => null,
                'record_status'           => 'idle',
                'fallback_recording_path' => $filepath,
            ]);

            Log::info("[Recording] {$channel->name}: completed {$filepath} ({$duration}s)");
        } else {
            $recording->update(['status' => 'failed', 'completed_at' => now()]);
            $channel->update(['record_pid' => null, 'record_status' => 'idle']);
        }

        // Prune old recordings (keep 3 most recent completed)
        $this->pruneOld($channel, keep: 3);
    }

    // ── Predicates ──────────────────────────────────────────────────────────

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'record'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function hasFallback(Channel $channel): bool
    {
        if ($channel->fallback_recording_path && file_exists($channel->fallback_recording_path)) {
            return true;
        }
        // Check disk
        $files = glob($channel->dvr_directory . '/rec_*.mp4') ?: [];
        return !empty($files);
    }

    public function shouldRecord(Channel $channel): bool
    {
        if ($channel->record_duration <= 0) return false;
        if ($this->isRunning($channel)) return false;
        if ($channel->record_status === 'recording') return false;
        if (!file_exists($channel->dvr_directory . '/live.m3u8')) return false;
        return true;
    }

    // ── Command builder ──────────────────────────────────────────────────────

    private function buildRecordCommand(Channel $channel, string $m3u8, string $output): array
    {
        return [
            $this->ffmpeg->getBin(),
            '-y',
            '-loglevel',         'warning',
            '-fflags',           '+genpts+igndts',
            '-allowed_extensions','ALL',
            '-protocol_whitelist','file,crypto,data,http,https,tcp,tls',
            '-i',                $m3u8,
            '-t',                (string) $channel->record_duration,  // stop after N seconds
            '-c:v',              'copy',
            '-c:a',              'copy',
            '-movflags',         '+faststart',   // moov atom at front for reliable looping
            '-avoid_negative_ts','make_zero',
            $output,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function probeDuration(string $file): ?float
    {
        try {
            $proc = new Process([
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'quiet', '-print_format', 'json', '-show_format', $file,
            ]);
            $proc->setTimeout(5);
            $proc->run();
            $data = json_decode($proc->getOutput(), true);
            return isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pruneOld(Channel $channel, int $keep = 3): void
    {
        $old = Recording::where('channel_id', $channel->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->skip($keep)
            ->take(100)
            ->get();

        foreach ($old as $rec) {
            if ($rec->filepath !== $channel->fallback_recording_path) {
                @unlink($rec->filepath);
            }
            $rec->delete();
        }
    }
}
