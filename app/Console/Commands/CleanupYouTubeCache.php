<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlaylistItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * CleanupYouTubeCache — removes expired YouTube streaming URL caches
 * and cleans up temporary downloaded files from the lazy-cache directory.
 *
 * Schedule: run every 30 minutes via scheduler.
 *   $schedule->command('youtube:cleanup-cache')->everyThirtyMinutes();
 */
class CleanupYouTubeCache extends Command
{
    protected $signature = 'youtube:cleanup-cache';

    protected $description = 'Clean up expired YouTube streaming URL caches and temporary downloaded files';

    public function handle(): int
    {
        $cleaned = 0;

        // 1. Clean up temporary YouTube downloads older than 2 hours
        $tempDir = storage_path('app/temp_cache');
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/yt_*') ?: [];
            $cutoff = time() - 7200; // 2 hours

            foreach ($files as $file) {
                if (filemtime($file) < $cutoff) {
                    @unlink($file);
                    $cleaned++;
                }
            }

            // Remove empty temp directory
            if (is_dir($tempDir) && count(glob($tempDir . '/*') ?: []) === 0) {
                @rmdir($tempDir);
            }
        }

        // 2. Clean up stale stream URL cache entries
        //    (Cache::forget is called on access, but we also scan for orphaned items)
        $youtubeItems = PlaylistItem::where('filepath', 'like', 'youtube:%')->get();
        foreach ($youtubeItems as $item) {
            $cacheKey = "yt_stream_url_{$item->id}";
            // If the item is no longer in any active playlist, clear its cache
            if (! $item->is_active) {
                Cache::forget($cacheKey);
                $cleaned++;
            }
        }

        // 3. Clean up temporary cookie files older than 1 hour
        $cookieFiles = glob(sys_get_temp_dir() . '/yt_cookies_*') ?: [];
        $cookieCutoff = time() - 3600;
        foreach ($cookieFiles as $cf) {
            if (filemtime($cf) < $cookieCutoff) {
                @unlink($cf);
                $cleaned++;
            }
        }

        $this->info("YouTube cache cleanup complete: {$cleaned} items cleaned");

        return self::SUCCESS;
    }
}
