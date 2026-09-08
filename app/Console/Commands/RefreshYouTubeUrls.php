<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\PlaylistItem;
use App\Services\TvPlayoutEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshYouTubeUrls extends Command
{
    protected $signature = 'youtube:refresh-urls';

    protected $description = 'Refresh expiring YouTube stream URLs for all active TV playout channels';

    public function handle(TvPlayoutEngine $engine): int
    {
        $cacheDir = storage_path('app/youtube_cache');

        $channels = Channel::where('source_type', 'tv_playout')
            ->where('is_active', true)
            ->get();

        foreach ($channels as $channel) {
            $items = PlaylistItem::where('channel_id', $channel->id)
                ->where('is_active', true)
                ->where('filepath', 'like', 'youtube:%')
                ->get();

            foreach ($items as $item) {
                $videoId = PlaylistItem::parseYouTubeId($item->filepath);
                if ($videoId === null) {
                    continue;
                }

                $urlCacheFile = "{$cacheDir}/{$videoId}.stream_url";

                if (file_exists($urlCacheFile)) {
                    $cached = trim((string) file_get_contents($urlCacheFile));
                    $cachedTime = filemtime($urlCacheFile);

                    $expiresAt = $this->getExpireTimestamp($cached);

                    if ($expiresAt !== null) {
                        $secondsUntilExpiry = $expiresAt - time();
                        if ($secondsUntilExpiry > 1800) {
                            continue;
                        }
                        Log::info("[YouTubeRefresh] {$videoId}: URL expires in " . round($secondsUntilExpiry / 60) . "min — refreshing");
                    } elseif ((time() - $cachedTime) < 6000) {
                        continue;
                    }
                }

                Log::info("[YouTubeRefresh] {$videoId}: extracting fresh stream URL for {$channel->name}");

                $method = new \ReflectionMethod($engine, 'extractStreamUrl');
                $method->setAccessible(true);
                $streamUrl = $method->invoke($engine, $videoId);

                if ($streamUrl !== null) {
                    if (! is_dir($cacheDir)) {
                        mkdir($cacheDir, 0755, true);
                    }
                    file_put_contents($urlCacheFile, $streamUrl);
                    Log::info("[YouTubeRefresh] {$videoId}: fresh URL cached");
                } else {
                    Log::warning("[YouTubeRefresh] {$videoId}: failed to extract stream URL");
                }
            }

            if ($engine->isRunning($channel)) {
                $pidFile = app(\App\Services\FFmpegService::class)->pidFile($channel, 'tv_playout');
                $pid = app(\App\Services\FFmpegService::class)->readPid($pidFile);
                if ($pid > 0) {
                    posix_kill($pid, 10);
                    Log::info("[YouTubeRefresh] {$channel->name}: sent SIGUSR1 to reload concat");
                }
            }
        }

        $this->info('YouTube stream URLs refreshed');
        return self::SUCCESS;
    }

    private function getExpireTimestamp(string $url): ?int
    {
        if (preg_match('/[?&]expire=(\d+)/', $url, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
