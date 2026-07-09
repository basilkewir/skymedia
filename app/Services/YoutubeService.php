<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class YoutubeService
{
    private const CACHE_TTL = 240; // seconds — YouTube HLS URLs expire ~6 min, refresh at 4

    /**
     * Resolve a YouTube watch/live URL to a playable HLS manifest URL.
     * Uses yt-dlp with the channel's stored cookies (Netscape format).
     * Result is cached for 4 minutes to avoid hammering YouTube.
     *
     * @throws \RuntimeException on extraction failure
     */
    public function resolveHlsUrl(Channel $channel): string
    {
        $cacheKey = "yt_hls_{$channel->id}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($channel) {
            return $this->extract($channel);
        });
    }

    /**
     * Force a fresh extraction, bypassing the cache.
     */
    public function refreshHlsUrl(Channel $channel): string
    {
        $cacheKey = "yt_hls_{$channel->id}";
        Cache::forget($cacheKey);
        $url = $this->extract($channel);
        Cache::put($cacheKey, $url, self::CACHE_TTL);
        return $url;
    }

    /**
     * Run yt-dlp and return the best HLS URL.
     * Tries multiple player clients with retries to handle YouTube anti-bot detection.
     */
    private function extract(Channel $channel): string
    {
        $ytdlp = $this->findBin();
        $url   = $channel->source_url;

        // Player clients to try: web (default), android, ios — different fingerprints
        $playerClients = ['web', 'android', 'ios'];
        $maxAttempts = count($playerClients) * 2; // 2 attempts per client

        $cookieFile = null;
        if (!empty($channel->youtube_cookies)) {
            $cookieFile = tempnam(sys_get_temp_dir(), 'yt_cookies_');
            file_put_contents($cookieFile, trim($channel->youtube_cookies));
        }

        $lastError = '';
        $attempt = 0;

        foreach ($playerClients as $client) {
            for ($i = 0; $i < 2; $i++) {
                $attempt++;
                Log::debug("[YouTube] Attempt {$attempt}: trying player_client={$client} for channel {$channel->id}");

                $cmd = [$ytdlp, '--js-runtimes', 'node', '--no-warnings', '-g',
                        '--format', 'best[protocol=m3u8_native]/best',
                        '--no-playlist',
                        '--extractor-args', "youtube:player_client={$client}"];

                if ($cookieFile) {
                    $cmd[] = '--cookies';
                    $cmd[] = $cookieFile;
                }

                $cmd[] = $url;

                $escaped = implode(' ', array_map('escapeshellarg', $cmd));
                $output  = [];
                $code    = 0;
                exec($escaped . ' 2>&1', $output, $code);

                $outputStr = implode("\n", $output);

                if ($code === 0) {
                    // Check for valid URL in output
                    foreach ($output as $line) {
                        $line = trim($line);
                        if (str_starts_with($line, 'https://') || str_starts_with($line, 'http://')) {
                            if ($cookieFile) {
                                @unlink($cookieFile);
                            }
                            Log::debug("[YouTube] Resolved {$url} → {$line} (client={$client}, attempt={$attempt})");
                            return $line;
                        }
                    }
                    // Got exit 0 but no URL — treat as failure
                    $lastError = "yt-dlp returned no URL. Output: {$outputStr}";
                } else {
                    $lastError = "yt-dlp failed (exit {$code}): {$outputStr}";
                }

                Log::warning("[YouTube] Attempt {$attempt} failed for channel {$channel->id}: {$lastError}");

                // Small delay between retries to let YouTube cool down
                if ($attempt < $maxAttempts) {
                    usleep(500_000); // 500ms
                }
            }
        }

        if ($cookieFile) {
            @unlink($cookieFile);
        }

        Log::error("[YouTube] All {$attempt} attempts failed for channel {$channel->id}: {$lastError}");
        throw new \RuntimeException("yt-dlp failed after {$attempt} attempts: {$lastError}");
    }

    private function findBin(): string
    {
        foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $path) {
            if (is_executable($path)) return $path;
        }
        $found = trim((string) shell_exec('which yt-dlp 2>/dev/null'));
        if ($found) return $found;
        throw new \RuntimeException('yt-dlp not found. Install it: curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp && chmod +x /usr/local/bin/yt-dlp');
    }

    /**
     * Quick validation: is this URL a YouTube watch/live/channel URL?
     */
    public static function isYoutubeUrl(string $url): bool
    {
        return (bool) preg_match(
            '#^https?://(www\.)?(youtube\.com/(watch|live|channel|c/|@)|youtu\.be/)#i',
            $url
        );
    }
}
