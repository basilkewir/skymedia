<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Models\PlaylistItem;
use App\Services\FFmpegService;
use App\Services\TvPlayoutEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TvPlayoutEngineTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/skymedia_tv_playout_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function createTvChannel(): Channel
    {
        $channel = Channel::factory()->create([
            'source_type'     => 'tv_playout',
            'dvr_path'        => $this->tempDir . '/channel_' . uniqid(),
            'segment_duration' => 2,
            'push_framerate'  => 25,
            'push_video_bitrate' => 3000,
            'push_audio_bitrate' => 128,
            'push_audio_samplerate' => 48000,
            'push_audio_channels' => 2,
            'ticker_text'     => 'Breaking news ticker',
            'ticker_enabled'  => false,
        ]);

        if (! is_dir($channel->dvr_directory)) {
            mkdir($channel->dvr_directory, 0755, true);
        }

        return $channel;
    }

    /** @test */
    public function it_rejects_non_tv_playout_channels(): void
    {
        $channel = Channel::factory()->create(['source_type' => 'hls']);
        $engine = app(TvPlayoutEngine::class);

        $this->assertFalse($engine->start($channel));
    }

    /** @test */
    public function it_returns_false_when_no_playlist_items(): void
    {
        $channel = $this->createTvChannel();
        $engine = app(TvPlayoutEngine::class);

        // No items → start fails
        $this->assertFalse($engine->start($channel));
    }

    /** @test */
    public function it_builds_concat_file_with_local_files(): void
    {
        $channel = $this->createTvChannel();

        // Create a dummy video file
        $videoFile = $channel->dvr_directory . '/test_video.mp4';
        file_put_contents($videoFile, str_repeat('x', 2048));

        PlaylistItem::factory()
            ->local($videoFile)
            ->ordered(0)
            ->create(['channel_id' => $channel->id, 'duration' => 10.0]);

        $playlistFile = $channel->dvr_directory . '/tv_playlist.txt';

        // Build concat file via reflection (private method)
        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('buildConcatFile');
        $method->setAccessible(true);

        $result = $method->invoke($engine, $channel);

        $this->assertNotNull($result);
        $this->assertFileExists($playlistFile);

        $content = file_get_contents($playlistFile);
        $this->assertStringContainsString("file '{$videoFile}'", $content);
    }

    /** @test */
    public function it_resolves_youtube_items_from_cache(): void
    {
        $channel = $this->createTvChannel();

        $item = PlaylistItem::factory()
            ->youtube('dQw4w9WgXcQ')
            ->ordered(0)
            ->create(['channel_id' => $channel->id]);

        // Cache a stream URL
        Cache::put("yt_stream_url_{$item->id}", 'https://example.com/stream.m3u8', now()->addHours(4));

        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('resolveFilePath');
        $method->setAccessible(true);

        $resolved = $method->invoke($engine, $item);

        $this->assertSame('https://example.com/stream.m3u8', $resolved);
    }

    /** @test */
    public function it_returns_null_for_uncached_youtube_items_and_dispatches_prefetch(): void
    {
        $channel = $this->createTvChannel();

        $item = PlaylistItem::factory()
            ->youtube('dQw4w9WgXcQ')
            ->ordered(0)
            ->create(['channel_id' => $channel->id]);

        // No cache → should return null
        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('resolveFilePath');
        $method->setAccessible(true);

        $resolved = $method->invoke($engine, $item);

        $this->assertNull($resolved);
    }

    /** @test */
    public function it_resolves_local_file_that_exists(): void
    {
        $channel = $this->createTvChannel();

        $videoFile = $channel->dvr_directory . '/test.mp4';
        file_put_contents($videoFile, str_repeat('x', 2048));

        $item = PlaylistItem::factory()
            ->local($videoFile)
            ->create(['channel_id' => $channel->id]);

        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('resolveFilePath');
        $method->setAccessible(true);

        $resolved = $method->invoke($engine, $item);

        $this->assertSame($videoFile, $resolved);
    }

    /** @test */
    public function it_returns_null_for_nonexistent_local_file(): void
    {
        $channel = $this->createTvChannel();

        $item = PlaylistItem::factory()
            ->local('/nonexistent/video.mp4')
            ->create(['channel_id' => $channel->id]);

        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('resolveFilePath');
        $method->setAccessible(true);

        $resolved = $method->invoke($engine, $item);

        $this->assertNull($resolved);
    }

    /** @test */
    public function it_returns_null_for_too_small_local_file(): void
    {
        $channel = $this->createTvChannel();

        $videoFile = $channel->dvr_directory . '/tiny.mp4';
        file_put_contents($videoFile, str_repeat('x', 500)); // under 1024 bytes

        $item = PlaylistItem::factory()
            ->local($videoFile)
            ->create(['channel_id' => $channel->id]);

        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('resolveFilePath');
        $method->setAccessible(true);

        $resolved = $method->invoke($engine, $item);

        $this->assertNull($resolved);
    }

    /** @test */
    public function it_recalculates_schedule_for_playlist_items(): void
    {
        $channel = $this->createTvChannel();

        PlaylistItem::factory()
            ->ordered(0)
            ->create(['channel_id' => $channel->id, 'duration' => 60.0]);

        PlaylistItem::factory()
            ->ordered(1)
            ->create(['channel_id' => $channel->id, 'duration' => 120.0]);

        PlaylistItem::factory()
            ->ordered(2)
            ->create(['channel_id' => $channel->id, 'duration' => 30.5]);

        $engine = app(TvPlayoutEngine::class);
        $result = $engine->recalculateSchedule($channel, '2026-09-06 10:00:00');

        $this->assertSame(210.5, $result['total_duration_seconds']);
        $this->assertSame(3, $result['item_count']);
        $this->assertStringContainsString('00:03:30.500', $result['formatted_total']);

        // Verify scheduled times were persisted
        $items = $channel->playlistItems()->orderBy('sort_order')->get();
        $this->assertCount(3, $items);

        $this->assertSame('2026-09-06 10:00:00', $items[0]->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-06 10:01:00', $items[0]->scheduled_end->format('Y-m-d H:i:s'));

        $this->assertSame('2026-09-06 10:01:00', $items[1]->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-06 10:03:00', $items[1]->scheduled_end->format('Y-m-d H:i:s'));

        $this->assertSame('2026-09-06 10:03:00', $items[2]->scheduled_start->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_writes_ticker_file(): void
    {
        $channel = $this->createTvChannel();
        mkdir($channel->dvr_directory . '/cg', 0755, true);

        $engine = app(TvPlayoutEngine::class);

        $engine->writeTickerFile($channel);

        $tickerFile = $channel->dvr_directory . '/cg/ticker.txt';
        $this->assertFileExists($tickerFile);
        $this->assertSame('Breaking news ticker', file_get_contents($tickerFile));
    }

    /** @test */
    public function it_writes_ticker_file_with_empty_text(): void
    {
        $channel = $this->createTvChannel();
        $channel->update(['ticker_text' => '']);
        mkdir($channel->dvr_directory . '/cg', 0755, true);

        $engine = app(TvPlayoutEngine::class);
        $engine->writeTickerFile($channel);

        $tickerFile = $channel->dvr_directory . '/cg/ticker.txt';
        $this->assertFileExists($tickerFile);
        // Empty text writes a single space so FFmpeg drawtext doesn't fail
        $this->assertSame(' ', file_get_contents($tickerFile));
    }

    /** @test */
    public function it_writes_meta_file(): void
    {
        $channel = $this->createTvChannel();
        mkdir($channel->dvr_directory . '/cg', 0755, true);

        PlaylistItem::factory()
            ->ordered(0)
            ->create([
                'channel_id' => $channel->id,
                'title'      => 'Test Video',
                'duration'   => 120.0,
            ]);

        $engine = app(TvPlayoutEngine::class);
        $engine->writeMetaFile($channel);

        $metaFile = $channel->dvr_directory . '/cg/current_playing.txt';
        $this->assertFileExists($metaFile);
        $content = file_get_contents($metaFile);
        $this->assertStringContainsString('NOW PLAYING: Test Video', $content);
    }

    /** @test */
    public function it_writes_meta_file_with_no_items(): void
    {
        $channel = $this->createTvChannel();
        mkdir($channel->dvr_directory . '/cg', 0755, true);

        $engine = app(TvPlayoutEngine::class);

        $engine->writeMetaFile($channel);

        $metaFile = $channel->dvr_directory . '/cg/current_playing.txt';
        $this->assertFileExists($metaFile);
        $this->assertSame('NO PLAYLIST ITEMS', file_get_contents($metaFile));
    }

    /** @test */
    public function it_updates_ticker_text(): void
    {
        $channel = $this->createTvChannel();
        mkdir($channel->dvr_directory . '/cg', 0755, true);

        $engine = app(TvPlayoutEngine::class);

        $engine->updateTicker($channel, 'Updated breaking news!');

        $channel->refresh();
        $this->assertSame('Updated breaking news!', $channel->ticker_text);

        $tickerFile = $channel->dvr_directory . '/cg/ticker.txt';
        $this->assertSame('Updated breaking news!', file_get_contents($tickerFile));
    }

    /** @test */
    public function it_builds_ffmpeg_command_with_all_components(): void
    {
        $channel = $this->createTvChannel();

        $videoFile = $channel->dvr_directory . '/test.mp4';
        file_put_contents($videoFile, str_repeat('x', 2048));

        PlaylistItem::factory()
            ->local($videoFile)
            ->ordered(0)
            ->create(['channel_id' => $channel->id, 'duration' => 10.0]);

        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);

        // Build concat file first
        $buildConcat = $reflection->getMethod('buildConcatFile');
        $buildConcat->setAccessible(true);
        $concatFile = $buildConcat->invoke($engine, $channel);

        // Build command
        $buildCmd = $reflection->getMethod('buildCommand');
        $buildCmd->setAccessible(true);
        $cmd = $buildCmd->invoke($engine, $channel, $concatFile);

        $cmdString = implode(' ', $cmd);

        $this->assertStringContainsString('-f concat', $cmdString);
        $this->assertStringContainsString('-filter_complex', $cmdString);
        $this->assertStringContainsString('drawtext', $cmdString); // clock overlay
        $this->assertStringContainsString('-f hls', $cmdString);
        $this->assertStringContainsString('-c:v libx264', $cmdString);
        $this->assertStringContainsString('-c:a aac', $cmdString);
        $this->assertStringContainsString('-stream_loop -1', $cmdString);
    }

    /** @test */
    public function is_running_returns_false_when_no_pid_file(): void
    {
        $channel = $this->createTvChannel();
        $engine = app(TvPlayoutEngine::class);

        $this->assertFalse($engine->isRunning($channel));
    }

    /** @test */
    public function stop_updates_channel_status(): void
    {
        $channel = $this->createTvChannel();
        $channel->update([
            'is_active'    => true,
            'stream_status' => 'live',
            'playout_status' => 'live',
        ]);

        $engine = app(TvPlayoutEngine::class);
        $engine->stop($channel);

        $channel->refresh();
        $this->assertFalse($channel->is_active);
        $this->assertSame('stopped', $channel->stream_status);
        $this->assertSame('stopped', $channel->playout_status);
        $this->assertNull($channel->playout_pid);
    }

    /** @test */
    public function schedule_uses_current_time_when_no_anchor_provided(): void
    {
        $channel = $this->createTvChannel();

        PlaylistItem::factory()
            ->ordered(0)
            ->create(['channel_id' => $channel->id, 'duration' => 60.0]);

        $engine = app(TvPlayoutEngine::class);
        $result = $engine->recalculateSchedule($channel);

        $item = $channel->playlistItems()->first();
        $now = now();

        // Should be scheduled within a few seconds of now
        $this->assertTrue(
            $item->scheduled_start->diffInSeconds($now) <= 2,
            'Scheduled start should be within 2 seconds of now'
        );
    }
}
