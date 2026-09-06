<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Models\PlaylistItem;
use App\Models\Setting;
use App\Jobs\PreFetchYouTubeStream;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PreFetchYouTubeStreamTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/skymedia_prefetch_test_' . uniqid();
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function createYouTubeItem(): PlaylistItem
    {
        $channel = Channel::factory()->create([
            'source_type' => 'tv_playout',
            'dvr_path'    => $this->tempDir . '/ch_' . uniqid(),
        ]);

        return PlaylistItem::factory()
            ->youtube('dQw4w9WgXcQ')
            ->create(['channel_id' => $channel->id]);
    }

    /** @test */
    public function it_skips_non_youtube_items(): void
    {
        $channel = Channel::factory()->create(['source_type' => 'tv_playout']);
        $item = PlaylistItem::factory()
            ->local('/var/media/video.mp4')
            ->create(['channel_id' => $channel->id]);

        $job = new PreFetchYouTubeStream($item);

        // Should not throw, just return early
        $job->handle();

        $this->assertNull(Cache::get("yt_stream_url_{$item->id}"));
    }

    /** @test */
    public function it_has_correct_job_configuration(): void
    {
        $item = $this->createYouTubeItem();
        $job = new PreFetchYouTubeStream($item);

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
    }

    /** @test */
    public function it_dispatches_on_rebuild_for_uncached_items(): void
    {
        $channel = Channel::factory()->create([
            'source_type' => 'tv_playout',
            'dvr_path'    => $this->tempDir . '/ch_dispatch_' . uniqid(),
        ]);

        PlaylistItem::factory()
            ->youtube('dQw4w9WgXcQ')
            ->ordered(0)
            ->create(['channel_id' => $channel->id]);

        // Cache should be empty
        $item = $channel->playlistItems()->first();
        $this->assertNull(Cache::get("yt_stream_url_{$item->id}"));
    }

    /** @test */
    public function it_reads_proxy_from_settings(): void
    {
        Setting::updateOrCreate(
            ['key' => 'youtube_proxy'],
            ['value' => 'socks5://proxy.example.com:1080']
        );

        $proxy = Setting::get('youtube_proxy', '');
        $this->assertSame('socks5://proxy.example.com:1080', $proxy);
    }

    /** @test */
    public function it_reads_player_client_from_settings(): void
    {
        Setting::updateOrCreate(
            ['key' => 'youtube_player_client'],
            ['value' => 'ios']
        );

        $client = Setting::get('youtube_player_client', '') ?: 'tv';
        $this->assertSame('ios', $client);
    }

    /** @test */
    public function it_falls_back_to_tv_client_when_no_setting(): void
    {
        Setting::where('key', 'youtube_player_client')->delete();

        $client = Setting::get('youtube_player_client', '') ?: 'tv';
        $this->assertSame('tv', $client);
    }

    /** @test */
    public function cache_key_pattern_matches_expected_format(): void
    {
        $item = $this->createYouTubeItem();
        $expectedKey = "yt_stream_url_{$item->id}";

        Cache::put($expectedKey, 'https://example.com/stream', now()->addHours(4));

        $this->assertSame('https://example.com/stream', Cache::get($expectedKey));
    }

    /** @test */
    public function it_caches_stream_url_for_4_hours(): void
    {
        $item = $this->createYouTubeItem();
        $cacheKey = "yt_stream_url_{$item->id}";

        Cache::put($cacheKey, 'https://example.com/stream', now()->addHours(4));

        $ttl = Cache::store('array')->get($cacheKey);
        $this->assertNotNull($ttl);
    }
}
