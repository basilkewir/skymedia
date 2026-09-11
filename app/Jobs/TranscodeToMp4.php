<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlaylistItem;
use App\Services\TvPlayoutEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Transcode any video file to H.264/AAC MP4 in the background.
 * When done, updates the playlist item filepath and rebuilds the concat file.
 * The channel keeps playing (jingles / other items) while this runs.
 */
class TranscodeToMp4 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200; // 2 hours max
    public int $tries   = 1;

    public function __construct(
        public readonly int    $itemId,
        public readonly string $inputPath,
        public readonly string $outputPath,
    ) {}

    public function handle(TvPlayoutEngine $engine): void
    {
        $item = PlaylistItem::find($this->itemId);
        if (! $item) {
            return;
        }

        Log::info("[Transcode] Item {$this->itemId}: {$this->inputPath} → {$this->outputPath}");

        $ffmpeg = config('skymedia.ffmpeg_binary', 'ffmpeg');

        // Transcode to H.264 + AAC MP4 with faststart for seeking
        $cmd = implode(' ', [
            escapeshellarg($ffmpeg),
            '-y',
            '-i', escapeshellarg($this->inputPath),
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '23',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-ar', '48000',
            '-ac', '2',
            '-movflags', '+faststart',
            escapeshellarg($this->outputPath),
            '>> ' . escapeshellarg($this->outputPath . '.log') . ' 2>&1',
        ]);

        exec($cmd, $out, $code);

        if ($code !== 0 || ! file_exists($this->outputPath) || filesize($this->outputPath) < 1024) {
            Log::error("[Transcode] Item {$this->itemId} failed (exit {$code})");
            @unlink($this->outputPath);
            return;
        }

        // Probe duration of the new file
        $ffprobe  = config('skymedia.ffprobe_binary', 'ffprobe');
        $duration = (float) trim((string) shell_exec(
            escapeshellarg($ffprobe) . ' -v error -show_entries format=duration'
            . ' -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($this->outputPath) . ' 2>/dev/null'
        ));

        if ($duration <= 0) {
            Log::error("[Transcode] Item {$this->itemId}: could not probe output duration");
            @unlink($this->outputPath);
            return;
        }

        // Delete original non-mp4 source
        if ($this->inputPath !== $this->outputPath) {
            @unlink($this->inputPath);
        }

        // Update DB
        $item->update([
            'filepath' => $this->outputPath,
            'duration' => $duration,
            'title'    => pathinfo($this->outputPath, PATHINFO_BASENAME),
        ]);

        Log::info("[Transcode] Item {$this->itemId} done — {$duration}s → {$this->outputPath}");

        // Rebuild concat seamlessly via SIGUSR1 — no restart needed
        $channel = $item->channel;
        if ($channel) {
            $engine->recalculateSchedule($channel);
            if ($engine->isRunning($channel)) {
                $engine->rebuild($channel);
            }
        }
    }
}
