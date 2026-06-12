<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Recording;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * RecordingService
 *
 * Records timed MP4 files from the DVR segments already on disk.
 * Reads from local .ts files (concat demuxer) — NOT from live.m3u8.
 * This avoids all HLS/network issues and works on any source type.
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

        $dvrDir   = $channel->dvr_directory;
        $segments = $this->getSegmentFiles($dvrDir);

        if (count($segments) < 2) {
            Log::debug("[Recording] {$channel->name}: need ≥2 segments, have " . count($segments));
            return false;
        }

        $filename = 'rec_' . now()->format('Ymd_His') . '.mp4';
        $filepath = "{$dvrDir}/{$filename}";
        $concat   = "{$dvrDir}/rec_concat.txt";

        // Write concat list of current segments
        $lines = array_map(fn($f) => "file '" . addslashes($f) . "'", $segments);
        file_put_contents($concat, implode("\n", $lines));

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

        $cmd     = $this->buildCommand($channel, $concat, $filepath);
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
                // Promote to fallback if no fallback exists yet
                if (!$channel->fallback_recording_path) {
                    $channel->update(['fallback_recording_path' => $recording->filepath]);
                }
            } else {
                $recording->update(['status' => 'failed', 'completed_at' => now()]);
            }
        }

        $channel->update(['record_pid' => null, 'record_status' => 'idle']);
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
        $this->ffmpeg->clearPid($this->ffmpeg->pidFile($channel, 'record'));

        $recording = Recording::where('channel_id', $channel->id)
            ->where('status', 'recording')
            ->latest('started_at')
            ->first();

        if (!$recording) {
            $channel->update(['record_pid' => null, 'record_status' => 'idle']);
            return;
        }

        $filepath = $recording->filepath;

        if (file_exists($filepath) && filesize($filepath) > 1024) {
            $duration = $this->probeDuration($filepath) ?? (float) $channel->record_duration;
            $filesize = filesize($filepath);

            $recording->update([
                'status'       => 'completed',
                'duration'     => $duration,
                'filesize'     => $filesize,
                'completed_at' => now(),
            ]);

            $channel->update([
                'record_pid'              => null,
                'record_status'           => 'idle',
                'fallback_recording_path' => $filepath,
            ]);

            Log::info(sprintf(
                "[Recording] %s completed: %s (%.1fs, %.1f MB)",
                $channel->name, $filepath, $duration, $filesize / 1_048_576
            ));
        } else {
            Log::warning("[Recording] {$channel->name} produced empty file: {$filepath}");
            $recording->update(['status' => 'failed', 'completed_at' => now()]);
            $channel->update(['record_pid' => null, 'record_status' => 'idle']);
        }

        @unlink($channel->dvr_directory . '/rec_concat.txt');
        $this->pruneOld($channel, keep: 3);
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
        if ($channel->record_duration <= 0) return false;
        if ($this->isRunning($channel)) return false;
        if ($channel->record_status === 'recording') return false;
        return count($this->getSegmentFiles($channel->dvr_directory)) >= 2;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function buildCommand(Channel $channel, string $concatFile, string $output): array
    {
        return [
            $this->ffmpeg->getBin(),
            '-y',
            '-loglevel',  'warning',
            '-stats',
            '-safe',      '0',
            '-f',         'concat',
            '-i',         $concatFile,
            '-t',         (string) $channel->record_duration,
            '-c:v',       'copy',
            '-c:a',       'copy',
            '-movflags',  '+faststart',
            '-avoid_negative_ts', 'make_zero',
            $output,
        ];
    }

    private function getSegmentFiles(string $dvrDir): array
    {
        $files = glob("{$dvrDir}/seg_*.ts") ?: [];
        natsort($files);
        return array_values(array_filter($files, fn($f) => file_exists($f) && filesize($f) > 0));
    }

    private function probeDuration(string $file): ?float
    {
        try {
            $proc = new Process([
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_format',
                $file,
            ]);
            $proc->setTimeout(8);
            $proc->run();
            $data = json_decode($proc->getOutput(), true);
            return isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pruneOld(Channel $channel, int $keep = 3): void
    {
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
    }
}
