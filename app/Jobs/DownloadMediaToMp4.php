<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ChannelMedia;
use App\Services\PlayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Download a remote media URL (HLS .m3u8, direct MP4, MKV, TS, etc.) and
 * transcode it to H.264 / AAC MP4 so it plays reliably in the content manager.
 *
 * The ChannelMedia record is created by the controller with the original URL
 * stored in filepath and is_active = false.  On success this job updates the
 * record to point at the local .mp4 file and flips it active.  On failure the
 * record is kept (filepath stays as the URL, is_active stays false) so the UI
 * can surface a "failed" indicator and the operator can retry.
 */
class DownloadMediaToMp4 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 14400; // 4 hours — covers long VOD files
    public int $tries   = 1;

    public function __construct(
        public readonly int    $mediaId,
        public readonly string $url,
        public readonly string $outputPath,
    ) {}

    public function handle(PlayoutService $playout): void
    {
        $media = ChannelMedia::with('channel')->find($this->mediaId);
        if (! $media) {
            Log::info("[DownloadMediaToMp4] Media {$this->mediaId} not found — nothing to do");

            return;
        }

        $channel    = $media->channel;
        $contentDir = $channel->dvr_directory . '/content';
        if (! is_dir($contentDir)) {
            mkdir($contentDir, 0755, true);
        }

        // Lock file lets the UI / downloadStatus endpoint know the job is alive.
        $lockFile = $contentDir . '/download_' . $this->mediaId . '.lock';
        @file_put_contents($lockFile, (string) getmypid());

        try {
            $ffmpeg  = config('skymedia.ffmpeg_binary', 'ffmpeg');
            $ffprobe = config('skymedia.ffprobe_binary', 'ffprobe');

            $outputDir = dirname($this->outputPath);
            if (! is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $cmd = [
                escapeshellarg($ffmpeg),
                '-y',
                '-loglevel', 'warning',
                '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
            ];

            // Browser-like User-Agent — most CDNs reject the default ffmpeg UA.
            $ua = trim((string) config('skymedia.http_user_agent', ''));
            if ($ua === '') {
                $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
            }
            $cmd[] = '-user_agent';
            $cmd[] = $ua;

            // Referer + Origin headers for CDN anti-bot compatibility.
            $origin = $this->urlOrigin($this->url);
            if ($origin !== null) {
                $cmd[] = '-referer';
                $cmd[] = $origin . '/';
                $cmd[] = '-headers';
                $cmd[] = $origin . "\r\n";
            }

            // Disable TLS certificate verification for HTTPS.
            if (str_starts_with(strtolower($this->url), 'https://')
                && config('skymedia.hls_tls_verify', false) === false) {
                $cmd[] = '-tls_verify';
                $cmd[] = '0';
            }

            // Brackets [ ] are valid in URLs but break ffmpeg's parser.
            $cmd[] = '-i';
            $cmd[] = $this->encodeUrlBrackets($this->url);

            // ── Output: H.264 + AAC MP4 with faststart for seeking ──
            $cmd[] = '-c:v';
            $cmd[] = 'libx264';
            $cmd[] = '-preset';
            $cmd[] = 'veryfast';
            $cmd[] = '-crf';
            $cmd[] = '23';
            $cmd[] = '-c:a';
            $cmd[] = 'aac';
            $cmd[] = '-b:a';
            $cmd[] = '128k';
            $cmd[] = '-ar';
            $cmd[] = '48000';
            $cmd[] = '-ac';
            $cmd[] = '2';
            $cmd[] = '-movflags';
            $cmd[] = '+faststart';
            $cmd[] = escapeshellarg($this->outputPath);

            $command = implode(' ', $cmd);
            Log::info("[DownloadMediaToMp4] Media {$this->mediaId}: running ffmpeg");

            
            $success = $code === 0
                && file_exists($this->outputPath)
                && filesize($this->outputPath) >= 1024;

            if (! $success) {
                Log::error("[DownloadMediaToMp4] Media {$this->mediaId} failed (exit {$code})");
                $this->failMedia($media);

                return;
            }

            $duration = (float) trim((string) shell_exec(
                escapeshellarg($ffprobe) . ' -v error -show_entries format=duration'
                . ' -of default=noprint_wrappers=1:nokey=1 '
                . escapeshellarg($this->outputPath) . ' 2>/dev/null'
            ));

            if ($duration <= 0) {
                Log::error("[DownloadMediaToMp4] Media {$this->mediaId}: could not probe output duration");
                $this->failMedia($media);

                return;
            }

            $fileSize = (int) filesize($this->outputPath);

            // Re-fetch in case the operator deleted the record during download.
            $media = ChannelMedia::with('channel')->find($this->mediaId);
            if (! $media) {
                @unlink($this->outputPath);
                Log::info("[DownloadMediaToMp4] Media {$this->mediaId}: record gone — cleaned up output file");

                return;
            }

            $media->update([
                'filepath'  => $this->outputPath,
                'mime_type' => 'video/mp4',
                'filesize'  => $fileSize,
                'is_active' => true,
            ]);

            // Account for the storage we just consumed.
            if ($fileSize > 0) {
                $media->channel->increment('storage_used_bytes', $fileSize);
            }

            Log::info("[DownloadMediaToMp4] Media {$media->id} done — {$duration}s, {$fileSize} bytes");

            // If the channel is in fallback mode, rebuild the fallback playlist
            // so the new VOD is picked up immediately.
            if ($media->channel->playout_status === 'fallback') {
                try {
                    $playout->switchToFallback($media->channel->fresh());
                    Log::info("[DownloadMediaToMp4] Media {$media->id}: fallback playout rebuilt");
                } catch (\Throwable $e) {
                    Log::warning("[DownloadMediaToMp4] Media {$media->id}: fallback rebuild failed: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            Log::error("[DownloadMediaToMp4] Media {$this->mediaId} exception: {$e->getMessage()}");
            $media = ChannelMedia::find($this->mediaId);
            if ($media) {
                $this->failMedia($media);
            }
        } finally {
            @unlink($lockFile);
        }
    }

    /**
     * Clean up after a failed download.  The media record is left in place
     * (filepath = original URL, is_active = false) so the UI can show a
     * "failed" state and the operator can retry.
     */
    private function failMedia(ChannelMedia $media): void
    {
        @unlink($this->outputPath);
        Log::warning("[DownloadMediaToMp4] Media {$media->id} download failed — record retained for retry");
    }

    /**
     * Percent-encode [ and ] in URLs — valid in URLs but treated as range
     * syntax by ffmpeg's URL parser.
     */
    private function encodeUrlBrackets(string $url): string
    {
        return str_replace(['[', ']'], ['%5B', '%5D'], $url);
    }

    /**
     * Origin (scheme://host[:port]) of a URL, or null when not HTTP(S).
     */
    private function urlOrigin(string $url): ?string
    {
        $p = parse_url($url);
        if (! isset($p['scheme'], $p['host'])) {
            return null;
        }
        $origin = strtolower($p['scheme']) . '://' . $p['host'];
        if (isset($p['port'])) {
            $origin .= ':' . $p['port'];
        }

        return $origin;
    }
}

