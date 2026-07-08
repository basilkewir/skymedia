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
     */
    private function extract(Channel $channel): string
    {
        $ytdlp = $this->findBin();
        $url   = $channel->source_url;

        $cmd = [$ytdlp, '--js-runtimes', 'node', '--no-warnings', '-g',
                '--format', 'best[protocol=m3u8_native]/best',
                '--no-playlist'];

        // Write cookies to a temp file if provided
        $cookieFile = null;
        if (!empty($channel->youtube_cookies)) {
            $cookieFile = tempnam(sys_get_temp_dir(), 'yt_cookies_');
            file_put_contents($cookieFile, trim($channel->youtube_cookies));
            array_push($cmd, '--cookies', $cookieFile);
        }

        $cmd[] = $url;

        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $output  = [];
        $code    = 0;
        exec($escaped . ' 2>&1', $output, $code);

        if ($cookieFile) {
            @unlink($cookieFile);
        }

        $outputStr = implode("\n", $output);

        if ($code !== 0) {
            Log::warning("[YouTube] yt-dlp failed for channel {$channel->id}: {$outputStr}");
            throw new \RuntimeException("yt-dlp failed (exit {$code}): {$outputStr}");
        }

        // yt-dlp may return multiple lines (audio+video) — take the first https line
        foreach ($output as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'https://') || str_starts_with($line, 'http://')) {
                Log::debug("[YouTube] Resolved {$url} → {$line}");
                return $line;
            }
        }

        throw new \RuntimeException("yt-dlp returned no URL. Output: {$outputStr}");
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
