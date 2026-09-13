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

        $ffmpeg  = config('skymedia.ffmpeg_binary', 'ffmpeg');
        $ffprobe = config('skymedia.ffprobe_binary', 'ffprobe');
        $logFile = $this->outputPath . '.log';

        // ── Step 1: Download raw file via yt-dlp (handles CDN tokens, brackets,
        //   redirects, MKV, MP4, TS — anything curl/yt-dlp can fetch) ──────────
        $rawPath  = $this->outputPath . '.raw';
        $ytdlp    = $this->findBinary(['yt-dlp', '/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp']);
        $downloaded = false;

        if ($ytdlp !== null) {
            // yt-dlp works for any HTTP URL, not just YouTube
            $dlCmd = implode(' ', [
                escapeshellarg($ytdlp),
                '--no-warnings',
                '--no-playlist',
                '-f', 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best',
                '--merge-output-format', 'mp4',
                '-o', escapeshellarg($rawPath),
                escapeshellarg($this->url),
                '>> ' . escapeshellarg($logFile) . ' 2>&1',
            ]);
            exec($dlCmd, $out, $dlCode);

            // yt-dlp may append .mp4 to the output path
            if (! file_exists($rawPath) && file_exists($rawPath . '.mp4')) {
                rename($rawPath . '.mp4', $rawPath);
            }

            $downloaded = $dlCode === 0 && file_exists($rawPath) && filesize($rawPath) >= 1024;
        }

        // Fallback: direct curl download (for plain CDN links yt-dlp can't handle)
        if (! $downloaded) {
            $encodedUrl = str_replace(['[', ']'], ['%5B', '%5D'], $this->url);
            $curlCmd = implode(' ', [
                'curl -L --max-time 14400 --retry 3',
                '-A', escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
                '-o', escapeshellarg($rawPath),
                escapeshellarg($encodedUrl),
                '>> ' . escapeshellarg($logFile) . ' 2>&1',
            ]);
            exec($curlCmd, $out, $dlCode);
            $downloaded = $dlCode === 0 && file_exists($rawPath) && filesize($rawPath) >= 1024;
        }

        if (! $downloaded) {
            Log::error("[DownloadTranscode] Item {$this->itemId}: download failed");
            @unlink($rawPath);
            $item->update(['media_type' => 'url_failed']);
            return;
        }

        // ── Step 2: Check if transcode is needed (skip if already H.264 MP4) ──
        $ext   = strtolower(pathinfo($rawPath, PATHINFO_EXTENSION));
        $codec = trim((string) shell_exec(
            escapeshellarg($ffprobe) . ' -v error -select_streams v:0'
            . ' -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($rawPath) . ' 2>/dev/null'
        ));

        $needsTranscode = ! ($ext === 'mp4' && $codec === 'h264');

        if (! $needsTranscode) {
            // Already H.264 MP4 — just move it
            rename($rawPath, $this->outputPath);
            $code = 0;
        } else {
            // ── Step 3: Transcode to H.264/AAC MP4 ──────────────────────────────
            $cmd = implode(' ', [
                escapeshellarg($ffmpeg),
                '-y',
                '-loglevel', 'warning',
                '-i', escapeshellarg($rawPath),
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-crf', '23',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-ar', '48000',
                '-ac', '2',
                '-movflags', '+faststart',
                escapeshellarg($this->outputPath),
                '>> ' . escapeshellarg($logFile) . ' 2>&1',
            ]);
            exec($cmd, $out, $code);
            @unlink($rawPath);
        }

        if ($code !== 0 || ! file_exists($this->outputPath) || filesize($this->outputPath) < 1024) {
            Log::error("[DownloadTranscode] Item {$this->itemId} failed (exit {$code})");
            @unlink($this->outputPath);
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

    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $found = trim((string) shell_exec('which ' . escapeshellarg($candidates[0]) . ' 2>/dev/null'));
        return $found !== '' ? $found : null;
    }
}
