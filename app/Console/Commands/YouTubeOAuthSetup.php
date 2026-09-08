<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlaylistItem;
use Illuminate\Console\Command;

/**
 * Set a direct stream URL for a YouTube playlist item.
 *
 * Usage:
 *   php artisan youtube:set-stream-url <playlist_item_id> <stream_url>
 */
class YouTubeOAuthSetup extends Command
{
    protected $signature = 'youtube:set-stream-url {itemId} {url}';
    protected $description = 'Set a direct stream URL for a YouTube playlist item';

    public function handle(): int
    {
        $itemId = (int) $this->argument('itemId');
        $url = $this->argument('url');

        $item = PlaylistItem::find($itemId);
        if (! $item) {
            $this->error("Playlist item #{$itemId} not found");
            return self::FAILURE;
        }

        $videoId = PlaylistItem::parseYouTubeId($item->filepath);
        if ($videoId === null) {
            $this->error("Playlist item #{$itemId} is not a YouTube item (filepath: {$item->filepath})");
            return self::FAILURE;
        }

        if (! str_starts_with($url, 'http')) {
            $this->error('URL must start with http:// or https://');
            return self::FAILURE;
        }

        $cacheDir = storage_path('app/youtube_cache');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $streamFile = "{$cacheDir}/{$videoId}.stream_url";
        file_put_contents($streamFile, $url);

        $this->info("Stream URL saved for video {$videoId}");
        $this->info("File: {$streamFile}");
        $this->info("URL: " . substr($url, 0, 80) . "...");

        // Clean up stale download files
        @unlink("{$cacheDir}/{$videoId}.downloading");
        @unlink("{$cacheDir}/{$videoId}.log");
        @unlink("{$cacheDir}/{$videoId}.sh");

        $this->info("Done! The playout engine will use this URL directly.");
        return self::SUCCESS;
    }
}
