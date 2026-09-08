<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlaylistItem;
use App\Models\Setting;
use App\Services\ProxyService;
use App\Services\YoutubeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * PreFetchYouTubeStream — extracts a fresh direct streaming URL for a YouTube
 * playlist item shortly before it is scheduled to air.
 *
 * YouTube streaming signatures expire after ~6 hours, so this job runs
 * 5 minutes before the item's scheduled_start time. The extracted URL
 * is cached for the TvPlayoutEngine to read when building the concat file.
 *
 * On failure, falls back to a local filler asset to keep the stream alive.
 */
class PreFetchYouTubeStream implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public PlaylistItem $item,
    ) {}

    public function handle(): void
    {
        $videoId = PlaylistItem::parseYouTubeId($this->item->filepath);
        if ($videoId === null) {
            Log::warning("[YouTubePreFetch] Item {$this->item->id} is not a YouTube video");
            return;
        }

        $youtubeUrl = "https://www.youtube.com/watch?v={$videoId}";
        $cacheKey = "yt_stream_url_{$this->item->id}";

        Log::info("[YouTubePreFetch] Extracting stream URL for item {$this->item->id} ({$videoId})");

        $directUrl = $this->extractStreamUrl($youtubeUrl);

        if ($directUrl !== null) {
            // Cache for 4 hours (YouTube URLs typically expire in ~6h)
            Cache::put($cacheKey, $directUrl, now()->addHours(4));
            Log::info("[YouTubePreFetch] Stream URL cached for item {$this->item->id}");

            // Rebuild concat file so the playout picks up this URL immediately
            try {
                $channel = $this->item->channel;
                $engine = app(\App\Services\TvPlayoutEngine::class);
                if ($engine->isRunning($channel)) {
                    $engine->rebuild($channel);
                    Log::info("[YouTubePreFetch] Rebuilt concat for channel {$channel->id}");
                }
            } catch (\Throwable $e) {
                Log::warning("[YouTubePreFetch] Rebuild failed: {$e->getMessage()}");
            }
        } else {
            // Fallback: cache the local filler asset path
            $filler = storage_path('app/media/branding_filler.mp4');
            if (file_exists($filler)) {
                Cache::put($cacheKey, $filler, now()->addHour());
                Log::warning("[YouTubePreFetch] Bot detection hit — using filler asset for item {$this->item->id}");
            } else {
                Log::error("[YouTubePreFetch] Extraction failed and no filler available for item {$this->item->id}");
            }
        }
    }

    /**
     * Extract direct streaming URL via yt-dlp with multiple client strategies.
     */
    private function extractStreamUrl(string $youtubeUrl): ?string
    {
        $ytdlp = $this->findYtdlp();
        if ($ytdlp === null) {
            Log::error("[YouTubePreFetch] yt-dlp not found");
            return null;
        }

        $cookiePath = $this->getCookiePath();

        // Player clients (tv_embedded unsupported since 2026.08.19)
        $playerClients = ['web', 'web_safari', 'ios'];

        // Get a working proxy from ProxyService (auto-refreshes from proxifly repo)
        /** @var ProxyService $proxyService */
        $proxyService = app(ProxyService::class);
        $proxy = $proxyService->getWorkingProxy()
            ?: (Setting::get('youtube_proxy', '') ?: '');

        $proxyFailed = false;

        foreach ($playerClients as $client) {
            $cmd = [
                $ytdlp,
                '--no-warnings',
                '-g',
                '--format', 'best[ext=mp4][height<=1080]/best[ext=mp4]/best',
                '--no-playlist',
                '--extractor-args', "youtube:player_client={$client}",
                '--js-runtimes', 'node',
            ];

            // Prefer OAuth2 over cookies
            $oauthToken = $this->getOAuth2TokenPath();
            if ($oauthToken !== null) {
                $cmd[] = '--username';
                $cmd[] = 'oauth2';
                $cmd[] = '--password';
                $cmd[] = '';
            } elseif ($cookiePath !== null) {
                $cmd[] = '--cookies';
                $cmd[] = $cookiePath;
            }

            if ($proxy !== '') {
                $cmd[] = '--proxy';
                $cmd[] = $proxy;
            }

            $cmd[] = $youtubeUrl;

            $proc = new Process($cmd);
            $proc->setTimeout(30);
            $proc->run();

            if ($proc->isSuccessful()) {
                $output = trim($proc->getOutput());
                $lines = array_filter(array_map('trim', explode("\n", $output)));
                $url = $lines[0] ?? '';

                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                    return $url;
                }
            }

            $err = strtolower(trim($proc->getErrorOutput()));
            Log::debug("[YouTubePreFetch] Client {$client} failed: {$err}");

            // If the proxy itself is being rejected, invalidate and try without
            if ($proxy !== '' && ! $proxyFailed && (
                str_contains($err, 'proxy') ||
                str_contains($err, 'connection') ||
                str_contains($err, 'tunnel') ||
                str_contains($err, 'timed out')
            )) {
                Log::warning("[YouTubePreFetch] Proxy {$proxy} appears dead — invalidating");
                $proxyService->invalidate();
                $proxy = Setting::get('youtube_proxy', '') ?: '';
                $proxyFailed = true;
            }

            usleep(300_000);
        }

        // All clients failed — force a proxy refresh for next attempt
        $proxyService->refresh();

        return null;
    }

    private function findYtdlp(): ?string
    {
        foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $found = trim((string) shell_exec('which yt-dlp 2>/dev/null'));

        return $found !== '' ? $found : null;
    }

    private function getCookiePath(): ?string
    {
        // Prefer global auth cookie file (source of truth, not overwritten by yt-dlp)
        $globalPath = storage_path('app/youtube_cookies_auth.txt');
        if (file_exists($globalPath) && filesize($globalPath) > 50) {
            return $globalPath;
        }

        // Fall back to channel-level cookies
        $channelCookies = $this->item->channel->youtube_cookies ?? '';
        if (! empty($channelCookies) && strlen($channelCookies) > 50) {
            $cookieFile = storage_path("app/youtube_cookies_auth_{$this->item->channel_id}.txt");
            file_put_contents($cookieFile, trim($channelCookies));

            return $cookieFile;
        }

        return null;
    }

    private function getOAuth2TokenPath(): ?string
    {
        $tokenPath = storage_path('app/youtube_oauth2.token');
        if (file_exists($tokenPath) && filesize($tokenPath) > 10) {
            return $tokenPath;
        }

        $homeToken = (getenv('HOME') ?: '/root') . '/.yt-dlp/oauth2.token';
        if (file_exists($homeToken) && filesize($homeToken) > 10) {
            return $homeToken;
        }

        return null;
    }
}
