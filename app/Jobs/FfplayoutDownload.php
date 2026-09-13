<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Download a URL into ffplayout's media directory and inject it into
 * the channel's today playlist JSON so it appears immediately in ffplayout.
 */
class FfplayoutDownload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 14400;
    public int $tries   = 1;

    public function __construct(
        public readonly string $downloadId,   // unique key for status tracking
        public readonly int    $channelId,    // ffplayout channel id (1 or 2)
        public readonly string $url,
        public readonly string $title,
        public readonly string $mediaDir,     // /home/ffpu/media or /home/ffpu/media/2
        public readonly string $playlistsDir, // /home/ffpu/playlists or /home/ffpu/playlists/2
    ) {}

    public function handle(): void
    {
        $statusFile = $this->statusFile();
        $logFile    = $this->mediaDir . '/' . $this->downloadId . '.log';

        file_put_contents($statusFile, json_encode(['status' => 'downloading', 'title' => $this->title]));

        $safeName   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $this->title);
        $safeName   = rtrim(substr($safeName, 0, 80), '._');
        $outputPath = $this->mediaDir . '/' . time() . '_' . $safeName . '.mp4';
        $rawPath    = $outputPath . '.raw';

        $ytdlp   = $this->findBinary(['yt-dlp', '/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp']);
        // Prefer a TLS-capable ffmpeg — the custom /usr/local/bin/ffmpeg 8.0 was
        // compiled without OpenSSL so it cannot open https:// URLs.
        $ffmpeg  = $this->findTlsFfmpeg();
        $ffprobe = $this->findBinary(['/usr/local/bin/ffprobe', 'ffprobe', '/usr/bin/ffprobe']);

        $downloaded = false;
        $isHls = (bool) preg_match('/\.m3u8?(\?|$)/i', strtok($this->url, '?') ?: $this->url);

        // Step 1a: HLS stream — use yt-dlp (handles HTTPS, segments, auth tokens)
        // Fall back to system ffmpeg (/usr/bin/ffmpeg has TLS, custom /usr/local/bin does not)
        if ($isHls) {
            if ($ytdlp) {
                $cmd = implode(' ', [
                    escapeshellarg($ytdlp),
                    '--no-warnings', '--no-playlist',
                    '--ffmpeg-location', '/usr/bin/ffmpeg',
                    '-f', 'best',
                    '-o', escapeshellarg($rawPath),
                    escapeshellarg($this->url),
                    '>> ' . escapeshellarg($logFile) . ' 2>&1',
                ]);
                exec($cmd, $out, $code);
                if (!file_exists($rawPath)) {
                    $glob = glob($rawPath . '.*');
                    $candidates = array_filter($glob ?? [], fn($f) => !str_ends_with($f, '.part'));
                    if (!empty($candidates)) {
                        rename(reset($candidates), $rawPath);
                    }
                }
                $downloaded = $code === 0 && file_exists($rawPath) && filesize($rawPath) >= 1024;
            }

            // yt-dlp fallback: system ffmpeg with TLS, -f mp4 needed for .raw extension
            if (!$downloaded) {
                $cmd = implode(' ', [
                    escapeshellarg('/usr/bin/ffmpeg'), '-y',
                    '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
                    '-allowed_extensions', 'ALL',
                    '-i', escapeshellarg($this->url),
                    '-c', 'copy',
                    '-bsf:a', 'aac_adtstoasc',
                    '-movflags', '+faststart',
                    '-f', 'mp4',
                    escapeshellarg($rawPath),
                    '>> ' . escapeshellarg($logFile) . ' 2>&1',
                ]);
                exec($cmd, $out, $code);
                $downloaded = $code === 0 && file_exists($rawPath) && filesize($rawPath) >= 1024;
            }
        }

        // Step 1b: non-HLS — try yt-dlp first, then curl
        if (!$downloaded && !$isHls) {
            if ($ytdlp) {
                $cmd = implode(' ', [
                    escapeshellarg($ytdlp),
                    '--no-warnings', '--no-playlist',
                    '-f', 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best',
                    '--merge-output-format', 'mp4',
                    '-o', escapeshellarg($rawPath),
                    escapeshellarg($this->url),
                    '>> ' . escapeshellarg($logFile) . ' 2>&1',
                ]);
                exec($cmd, $out, $code);
                if (!file_exists($rawPath)) {
                    $glob = glob($rawPath . '.*');
                    $candidates = array_filter($glob ?? [], fn($f) => !str_ends_with($f, '.part'));
                    if (!empty($candidates)) rename(reset($candidates), $rawPath);
                }
                $downloaded = $code === 0 && file_exists($rawPath) && filesize($rawPath) >= 1024;
            }

            if (!$downloaded) {
                $encodedUrl = str_replace(['[', ']'], ['%5B', '%5D'], $this->url);
                $cmd = 'curl -L --max-time 14400 --retry 3 -A ' . escapeshellarg('Mozilla/5.0') .
                       ' -o ' . escapeshellarg($rawPath) . ' ' . escapeshellarg($encodedUrl) .
                       ' >> ' . escapeshellarg($logFile) . ' 2>&1';
                exec($cmd, $out, $code);
                $downloaded = $code === 0 && file_exists($rawPath) && filesize($rawPath) >= 1024;
            }
        }

        if (!$downloaded) {
            @unlink($rawPath);
            file_put_contents($statusFile, json_encode(['status' => 'failed', 'title' => $this->title]));
            Log::error("[FfplayoutDownload] {$this->downloadId}: download failed");
            return;
        }

        // Step 2: transcode if needed (skip for HLS — ffmpeg already wrote h264 MP4)
        $ext   = strtolower(pathinfo($rawPath, PATHINFO_EXTENSION));
        $codec = trim((string) shell_exec(
            escapeshellarg($ffprobe) . ' -v error -select_streams v:0' .
            ' -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 ' .
            escapeshellarg($rawPath) . ' 2>/dev/null'
        ));

        if ($isHls || ($ext === 'mp4' && $codec === 'h264')) {
            rename($rawPath, $outputPath);
        } else {
            $cmd = implode(' ', [
                escapeshellarg($ffmpeg), '-y', '-loglevel', 'warning',
                '-i', escapeshellarg($rawPath),
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
                '-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-ac', '2',
                '-movflags', '+faststart',
                escapeshellarg($outputPath),
                '>> ' . escapeshellarg($logFile) . ' 2>&1',
            ]);
            exec($cmd, $out, $code);
            @unlink($rawPath);

            if ($code !== 0 || !file_exists($outputPath) || filesize($outputPath) < 1024) {
                @unlink($outputPath);
                file_put_contents($statusFile, json_encode(['status' => 'failed', 'title' => $this->title]));
                Log::error("[FfplayoutDownload] {$this->downloadId}: transcode failed");
                return;
            }
        }

        // Step 3: probe duration
        $duration = (float) trim((string) shell_exec(
            escapeshellarg($ffprobe) . ' -v error -show_entries format=duration' .
            ' -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($outputPath) . ' 2>/dev/null'
        ));

        if ($duration <= 0) {
            @unlink($outputPath);
            file_put_contents($statusFile, json_encode(['status' => 'failed', 'title' => $this->title]));
            return;
        }

        // Step 4: inject into today's ffplayout playlist JSON
        $this->injectIntoPlaylist($outputPath, $duration);

        file_put_contents($statusFile, json_encode([
            'status'   => 'ready',
            'title'    => $this->title,
            'file'     => basename($outputPath),
            'duration' => $duration,
        ]));

        // Auto-update the movie title overlay with the downloaded title
        $this->writeTitleOverlay($this->title);

        Log::info("[FfplayoutDownload] {$this->downloadId}: done — {$duration}s → {$outputPath}");
    }

    private function injectIntoPlaylist(string $filePath, float $duration): void
    {
        $today = date('Y-m-d');
        [$y, $m, $d] = explode('-', $today);
        $dir = "{$this->playlistsDir}/{$y}/{$m}";
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $jsonFile = "{$dir}/{$today}.json";

        $playlist = file_exists($jsonFile)
            ? (json_decode(file_get_contents($jsonFile), true) ?? [])
            : [];

        if (empty($playlist)) {
            $playlist = [
                'channel' => (string) $this->channelId,
                'date'    => $today,
                'program' => [],
            ];
        }

        // Calculate next in/out times
        $program = $playlist['program'] ?? [];

        // in/out are clip-relative positions (seconds into the file), NOT timeline offsets.
        // Always start from second 0 of the file and play to the end.
        $program[] = [
            'in'            => 0.0,
            'out'           => round($duration, 3),
            'duration'      => round($duration, 3),
            'source'        => $filePath,
            'audio_track'   => 0,
            'custom_filter' => '',
        ];

        $playlist['program'] = $program;
        file_put_contents($jsonFile, json_encode($playlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($jsonFile, 0664);
    }

    private function writeTitleOverlay(string $title): void
    {
        $assetsDir = $this->mediaDir . '/00-assets';
        if (!is_dir($assetsDir)) return;

        $titleFile = $assetsDir . '/title.txt';
        file_put_contents($titleFile, 'Now Playing: ' . $title);
        @chmod($titleFile, 0664);

        // Update sidecar JSON so the UI reflects the current title
        $sidecarPath = $assetsDir . '/overlay.json';
        $sidecar = file_exists($sidecarPath)
            ? (json_decode(file_get_contents($sidecarPath), true) ?? [])
            : [];
        $sidecar['title_text'] = 'Now Playing: ' . $title;
        file_put_contents($sidecarPath, json_encode($sidecar));
    }

public function statusFile(): string
    {
        return $this->mediaDir . '/' . $this->downloadId . '.status.json';
    }

    private function findTlsFfmpeg(): string
    {
        // /usr/bin/ffmpeg (system, has TLS) preferred over /usr/local/bin/ffmpeg (no TLS)
        foreach (['/usr/bin/ffmpeg', 'ffmpeg', '/usr/local/bin/ffmpeg'] as $path) {
            if (is_executable($path)) return $path;
        }
        return 'ffmpeg';
    }

    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if (is_executable($path)) return $path;
        }
        $found = trim((string) shell_exec('which ' . escapeshellarg($candidates[0]) . ' 2>/dev/null'));
        return $found !== '' ? $found : null;
    }
}
