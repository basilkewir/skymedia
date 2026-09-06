<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlaylistItem;
use App\Models\Setting;
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

        // Read player client preference from settings, with fallback chain
        $preferredClient = Setting::get('youtube_player_client', '') ?: 'tv';
        // Build client list starting with the preferred client
        $allClients = ['tv', 'tv_embedded', 'web', 'ios', 'android'];
        $playerClients = array_unique(array_merge([$preferredClient], $allClients));

        // Read proxy from settings
        $proxy = Setting::get('youtube_proxy', '') ?: '';

        foreach ($playerClients as $client) {
            $cmd = [
                $ytdlp,
                '--no-warnings',
                '-g',
                '--format', 'bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/best[ext=mp4]/best',
                '--no-playlist',
                '--extractor-args', "youtube:player_client={$client}",
            ];

            if ($cookiePath !== null) {
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
                // yt-dlp -g outputs one URL per line (video + audio if separate)
                $lines = array_filter(explode("\n", $output));
                $url = trim($lines[0] ?? '');

                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                    return $url;
                }
            }

            Log::debug("[YouTubePreFetch] Client {$client} failed: " . trim($proc->getErrorOutput()));
            usleep(300_000); // 300ms cooldown between clients
        }

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
        // Check channel-level cookies first
        $channelCookies = $this->item->channel->youtube_cookies ?? '';
        if (! empty($channelCookies)) {
            $cookieFile = sys_get_temp_dir() . '/yt_cookies_' . $this->item->channel_id . '.txt';
            file_put_contents($cookieFile, trim($channelCookies));

            return $cookieFile;
        }

        // Fall back to global cookie file
        $globalPath = storage_path('app/youtube_cookies.txt');
        if (file_exists($globalPath)) {
            return $globalPath;
        }

        return null;
    }
}
