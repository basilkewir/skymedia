<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Models\PlaylistItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CleanupYouTubeCacheTest extends TestCase
{
    /** @test */
    public function it_returns_success_status(): void
    {
        $this->artisan('youtube:cleanup-cache')
            ->expectsOutputToContain('YouTube cache cleanup complete')
            ->assertSuccessful();
    }

    /** @test */
    public function it_cleans_old_temp_files(): void
    {
        $tempDir = storage_path('app/temp_cache');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Create a file that's "old" (simulate by touching it in the past)
        $oldFile = $tempDir . '/yt_old_video_' . uniqid() . '.mp4';
        file_put_contents($oldFile, 'data');
        touch($oldFile, time() - 10000); // 10000 seconds ago

        // Create a recent file
        $recentFile = $tempDir . '/yt_recent_video_' . uniqid() . '.mp4';
        file_put_contents($recentFile, 'data');

        $this->artisan('youtube:cleanup-cache')->assertSuccessful();

        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($recentFile);

        @unlink($recentFile);
    }

    /** @test */
    public function it_cleans_stale_cache_for_inactive_items(): void
    {
        $channel = Channel::factory()->create(['source_type' => 'tv_playout']);
        $item = PlaylistItem::factory()
            ->youtube('stale_video')
            ->inactive()
            ->create(['channel_id' => $channel->id]);

        Cache::put("yt_stream_url_{$item->id}", 'https://example.com/stale', now()->addHours(4));

        $this->artisan('youtube:cleanup-cache')->assertSuccessful();

        $this->assertNull(Cache::get("yt_stream_url_{$item->id}"));
    }

    /** @test */
    public function it_preserves_cache_for_active_items(): void
    {
        $channel = Channel::factory()->create(['source_type' => 'tv_playout']);
        $item = PlaylistItem::factory()
            ->youtube('active_video')
            ->create(['channel_id' => $channel->id]);

        Cache::put("yt_stream_url_{$item->id}", 'https://example.com/active', now()->addHours(4));

        $this->artisan('youtube:cleanup-cache')->assertSuccessful();

        $this->assertSame('https://example.com/active', Cache::get("yt_stream_url_{$item->id}"));
    }

    /** @test */
    public function it_cleans_old_cookie_files(): void
    {
        $cookieFile = sys_get_temp_dir() . '/yt_cookies_' . uniqid() . '.txt';
        file_put_contents($cookieFile, 'cookie data');
        touch($cookieFile, time() - 7200); // 2 hours ago

        $this->artisan('youtube:cleanup-cache')->assertSuccessful();

        $this->assertFileDoesNotExist($cookieFile);
    }

    /** @test */
    public function it_preserves_recent_cookie_files(): void
    {
        $cookieFile = sys_get_temp_dir() . '/yt_cookies_' . uniqid() . '.txt';
        file_put_contents($cookieFile, 'cookie data');

        $this->artisan('youtube:cleanup-cache')->assertSuccessful();

        $this->assertFileExists($cookieFile);

        @unlink($cookieFile);
    }

    /** @test */
    public function it_handles_missing_temp_directory(): void
    {
        $this->artisan('youtube:cleanup-cache')->assertSuccessful();
    }

    /** @test */
    public function it_handles_empty_temp_directory(): void
    {
        $tempDir = storage_path('app/temp_cache');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $this->artisan('youtube:cleanup-cache')->assertSuccessful();
    }

    /** @test */
    public function it_does_not_clean_non_youtube_temp_files(): void
    {
        $tempDir = storage_path('app/temp_cache');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $otherFile = $tempDir . '/other_file_' . uniqid() . '.mp4';
        file_put_contents($otherFile, 'data');
        touch($otherFile, time() - 10000);

        $this->artisan('youtube:cleanup-cache')->assertSuccessful();

        // Non-yt_ prefixed files should not be touched
        $this->assertFileExists($otherFile);

        @unlink($otherFile);
    }
}
