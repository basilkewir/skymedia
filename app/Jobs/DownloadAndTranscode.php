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
 * Download a remote URL and transcode to H.264/AAC MP4.
 * Works for direct file URLs (mp4, mkv, avi, ts, etc.) and HLS VOD streams.
 * Updates the playlist item filepath when done and rebuilds concat via SIGUSR1.
 */
class DownloadAndTranscode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 14400; // 4 hours max
    public int $tries   = 1;

    public function __construct(
        public readonly int    $itemId,
        public readonly string $url,
        public readonly string $outputPath,
    ) {}

    public function handle(TvPlayoutEngine $engine): void
    {
        $item = PlaylistItem::find($this->itemId);
        if (! $item) {
            return;
        }

        Log::info("[DownloadTranscode] Item {$this->itemId}: {$this->url} → {$this->outputPath}");

        $ffmpeg = config('skymedia.ffmpeg_binary', 'ffmpeg');

        // Use ffmpeg to download + transcode in one pass.
        // This handles HLS, direct MP4, MKV, TS, AVI — anything ffmpeg can read.
        $cmd = implode(' ', [
            escapeshellarg($ffmpeg),
            '-y',
            '-loglevel', 'warning',
            '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
            '-i', escapeshellarg($this->url),
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
            Log::error("[DownloadTranscode] Item {$this->itemId} failed (exit {$code})");
            @unlink($this->outputPath);
            // Mark item as failed but keep URL so user can retry
            $item->update(['media_type' => 'url_failed']);
            return;
        }

        // Probe duration
        $ffprobe  = config('skymedia.ffprobe_binary', 'ffprobe');
        $duration = (float) trim((string) shell_exec(
            escapeshellarg($ffprobe) . ' -v error -show_entries format=duration'
            . ' -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($this->outputPath) . ' 2>/dev/null'
        ));

        if ($duration <= 0) {
            Log::error("[DownloadTranscode] Item {$this->itemId}: could not probe output duration");
            @unlink($this->outputPath);
            return;
        }

        $item->update([
            'filepath'   => $this->outputPath,
            'duration'   => $duration,
            'media_type' => 'local',
            'title'      => $item->title ?: pathinfo($this->outputPath, PATHINFO_BASENAME),
        ]);

        Log::info("[DownloadTranscode] Item {$this->itemId} done — {$duration}s");

        $channel = $item->channel;
        if ($channel) {
            $engine->recalculateSchedule($channel);
            if ($engine->isRunning($channel)) {
                $engine->rebuild($channel);
            }
        }
    }
}
