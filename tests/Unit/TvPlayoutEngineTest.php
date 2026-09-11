<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Models\PlaylistItem;
use App\Services\FFmpegService;
use App\Services\TvPlayoutEngine;
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
            'source_type'           => 'tv_playout',
            'dvr_path'              => $this->tempDir . '/channel_' . uniqid(),
            'segment_duration'      => 2,
            'push_framerate'        => 25,
            'push_video_bitrate'    => 3000,
            'push_audio_bitrate'    => 128,
            'push_audio_samplerate' => 48000,
            'push_audio_channels'   => 2,
            'ticker_text'           => 'Breaking news ticker',
            'ticker_enabled'        => false,
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

        $this->assertFalse($engine->start($channel));
    }

    /** @test */
    public function it_builds_concat_file_with_local_files(): void
    {
        $channel = $this->createTvChannel();

        $videoFile = $channel->dvr_directory . '/test_video.mp4';
        file_put_contents($videoFile, str_repeat('x', 2048));

        PlaylistItem::factory()
            ->local($videoFile)
            ->ordered(0)
            ->create(['channel_id' => $channel->id, 'duration' => 10.0]);

        $playlistFile = $channel->dvr_directory . '/tv_playlist.txt';

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

        // Write a stream URL cache file (the actual on-disk mechanism)
        $cacheDir = storage_path('app/youtube_cache');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $urlCacheFile = "{$cacheDir}/dQw4w9WgXcQ.stream_url";
        file_put_contents($urlCacheFile, 'https://example.com/stream.m3u8');
        touch($urlCacheFile); // ensure mtime is now (age < 7200s)

        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('resolveFilePath');
        $method->setAccessible(true);

        $resolved = $method->invoke($engine, $item);

        @unlink($urlCacheFile);

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

        // Ensure no cache file exists
        $cacheDir = storage_path('app/youtube_cache');
        @unlink("{$cacheDir}/dQw4w9WgXcQ.stream_url");
        @unlink("{$cacheDir}/dQw4w9WgXcQ.mp4");

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

        PlaylistItem::factory()->ordered(0)->create(['channel_id' => $channel->id, 'duration' => 60.0]);
        PlaylistItem::factory()->ordered(1)->create(['channel_id' => $channel->id, 'duration' => 120.0]);
        PlaylistItem::factory()->ordered(2)->create(['channel_id' => $channel->id, 'duration' => 30.5]);

        $engine = app(TvPlayoutEngine::class);
        $result = $engine->recalculateSchedule($channel, '2026-09-06 10:00:00');

        $this->assertSame(210.5, $result['total_duration_seconds']);
        $this->assertSame(3, $result['item_count']);
        $this->assertStringContainsString('00:03:30.500', $result['formatted_total']);
        $this->assertArrayHasKey('anchor_start', $result);
        $this->assertArrayHasKey('end_anchor', $result);

        $items = $channel->playlistItems()->orderBy('sort_order')->get();
        $this->assertCount(3, $items);

        $this->assertSame('2026-09-06 10:00:00', $items[0]->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-06 10:01:00', $items[0]->scheduled_end->format('Y-m-d H:i:s'));

        $this->assertSame('2026-09-06 10:01:00', $items[1]->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-06 10:03:00', $items[1]->scheduled_end->format('Y-m-d H:i:s'));

        $this->assertSame('2026-09-06 10:03:00', $items[2]->scheduled_start->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function schedule_is_stable_across_page_loads_when_last_live_at_is_set(): void
    {
        $channel = $this->createTvChannel();
        $anchor = now()->subHours(1);
        $channel->update(['last_live_at' => $anchor]);

        PlaylistItem::factory()->ordered(0)->create(['channel_id' => $channel->id, 'duration' => 60.0]);
        PlaylistItem::factory()->ordered(1)->create(['channel_id' => $channel->id, 'duration' => 120.0]);

        $engine = app(TvPlayoutEngine::class);

        // First call
        $result1 = $engine->recalculateSchedule($channel->fresh());
        // Second call (simulates page reload)
        $result2 = $engine->recalculateSchedule($channel->fresh());

        // Both calls must produce identical anchor_start
        $this->assertSame($result1['anchor_start'], $result2['anchor_start']);
        $this->assertSame($result1['end_anchor'], $result2['end_anchor']);
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
        $this->assertStringContainsString('NOW PLAYING: Test Video', file_get_contents($metaFile));
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
    public function it_reads_playout_offset_from_progress_file(): void
    {
        $channel = $this->createTvChannel();

        $progressFile = $channel->dvr_directory . '/tv_progress.txt';
        $content = implode("\n", [
            'frame=120',
            'fps=25.00',
            'out_time_us=123456789',
            'out_time=123.474440',
            'progress=continue',
        ]);
        file_put_contents($progressFile, $content);

        $engine = app(TvPlayoutEngine::class);
        $offset = $engine->readPlayoutOffset($progressFile);

        $this->assertSame(123.456789, $offset);
    }

    /** @test */
    public function it_returns_null_when_progress_file_has_no_output(): void
    {
        $channel = $this->createTvChannel();
        $progressFile = $channel->dvr_directory . '/tv_progress.txt';

        $engine = app(TvPlayoutEngine::class);
        $this->assertNull($engine->readPlayoutOffset('/nonexistent/progress.txt'));

        file_put_contents($progressFile, "progress=continue\n");
        $this->assertNull($engine->readPlayoutOffset($progressFile));
    }

    /** @test */
    public function it_writes_meta_file_using_actual_playback_offset(): void
    {
        $channel = $this->createTvChannel();
        mkdir($channel->dvr_directory . '/cg', 0755, true);

        // Two items: 100s then 50s (total 150s)
        PlaylistItem::factory()
            ->ordered(0)
            ->create(['channel_id' => $channel->id, 'title' => 'First',   'duration' => 100.0]);
        PlaylistItem::factory()
            ->ordered(1)
            ->create(['channel_id' => $channel->id, 'title' => 'Second',  'duration' => 50.0]);

        $engine = app(TvPlayoutEngine::class);
        $metaFile = $channel->dvr_directory . '/cg/current_playing.txt';

        // Offset 30s → still First
        $engine->writeMetaFile($channel->fresh(), 30.0);
        $this->assertStringContainsString('First', file_get_contents($metaFile));

        // Offset 120s → Second (100 + 20 into second item)
        $engine->writeMetaFile($channel->fresh(), 120.0);
        $this->assertStringContainsString('Second', file_get_contents($metaFile));

        // Offset wraps around the loop (150s total) → back to First (150 % 150 = 0)
        $engine->writeMetaFile($channel->fresh(), 150.0);
        $this->assertStringContainsString('First', file_get_contents($metaFile));
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

        // ensureLogoBlank writes logo_blank.png into cg/ — create it first
        mkdir($channel->dvr_directory . '/cg', 0755, true);

        PlaylistItem::factory()
            ->local($videoFile)
            ->ordered(0)
            ->create(['channel_id' => $channel->id, 'duration' => 10.0]);

        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);

        $buildConcat = $reflection->getMethod('buildConcatFile');
        $buildConcat->setAccessible(true);
        $concatFile = $buildConcat->invoke($engine, $channel);

        // Test Part 1: Playout command (stream copy, no overlays)
        $buildPlayoutCmd = $reflection->getMethod('buildPlayoutCommand');
        $buildPlayoutCmd->setAccessible(true);
        $playoutCmd = $buildPlayoutCmd->invoke($engine, $channel, $concatFile);

        $playoutCmdString = implode(' ', $playoutCmd);
        $this->assertStringContainsString('-f concat', $playoutCmdString);
        $this->assertStringContainsString('-c:v copy', $playoutCmdString);
        $this->assertStringContainsString('-c:a copy', $playoutCmdString);
        $this->assertStringContainsString('-hls_segment_filename', $playoutCmdString);
        $this->assertStringContainsString('raw_%010d.ts', $playoutCmdString);
        $this->assertStringNotContainsString('-filter_complex', $playoutCmdString);
        $this->assertStringNotContainsString('drawtext', $playoutCmdString);

        // Test Part 2: CG command (overlays applied)
        $buildCgCmd = $reflection->getMethod('buildCgCommand');
        $buildCgCmd->setAccessible(true);
        $cgCmd = $buildCgCmd->invoke($engine, $channel);

        $cgCmdString = implode(' ', $cgCmd);
        $this->assertStringContainsString('-filter_complex', $cgCmdString);
        $this->assertStringContainsString('drawtext', $cgCmdString);
        $this->assertStringContainsString('-f hls', $cgCmdString);
        $this->assertStringContainsString('-c:v libx264', $cgCmdString);
        $this->assertStringContainsString('-c:a aac', $cgCmdString);
        $this->assertStringContainsString('branded_%010d.ts', $cgCmdString);
        // Looping must come from the concat file repeats, NOT -stream_loop
        $this->assertStringNotContainsString('-stream_loop', $cgCmdString);
    }

    /** @test */
    public function it_loops_the_full_playlist_in_concat_file(): void
    {
        $channel = $this->createTvChannel();

        $videoA = $channel->dvr_directory . '/a.mp4';
        $videoB = $channel->dvr_directory . '/b.mp4';
        file_put_contents($videoA, str_repeat('x', 2048));
        file_put_contents($videoB, str_repeat('y', 2048));

        PlaylistItem::factory()->local($videoA)->ordered(0)
            ->create(['channel_id' => $channel->id, 'duration' => 30.0]);
        PlaylistItem::factory()->local($videoB)->ordered(1)
            ->create(['channel_id' => $channel->id, 'duration' => 10.0]);

        // Explicitly loop 3 times
        $channel->update(['playlist_loop' => 3]);

        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('buildConcatFile');
        $method->setAccessible(true);

        $concatFile = $method->invoke($engine, $channel->fresh());
        $content = file_get_contents($concatFile);

        $needleA = "file '" . $videoA . "'";
        $needleB = "file '" . $videoB . "'";

        // Each pass must contain the FULL playlist in order (a then b), 3 times.
        $this->assertSame(3, count(explode($needleA, $content)) - 1);
        $this->assertSame(3, count(explode($needleB, $content)) - 1);

        $firstA = mb_strpos($content, $needleA);
        $firstB = mb_strpos($content, $needleB);
        $this->assertTrue($firstA < $firstB);
        $secondA = mb_strpos($content, $needleA, $firstB);
        $this->assertTrue($firstB < $secondA);
        // Order repeated again for the 3rd pass
        $secondB = mb_strpos($content, $needleB, $secondA);
        $this->assertTrue($secondA < $secondB);
    }

    /** @test */
    public function it_auto_loops_based_on_actual_included_files(): void
    {
        $channel = $this->createTvChannel();

        $videoA = $channel->dvr_directory . '/a.mp4';
        $videoB = $channel->dvr_directory . '/b.mp4';
        file_put_contents($videoA, str_repeat('x', 2048));
        file_put_contents($videoB, str_repeat('y', 2048));

        PlaylistItem::factory()->local($videoA)->ordered(0)
            ->create(['channel_id' => $channel->id, 'duration' => 60.0]);
        PlaylistItem::factory()->local($videoB)->ordered(1)
            ->create(['channel_id' => $channel->id, 'duration' => 60.0]);

        // playlist_loop = 0 → auto-fill 24h: 120s playlist → 720 passes
        $channel->update(['playlist_loop' => 0]);

        $engine = app(TvPlayoutEngine::class);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('buildConcatFile');
        $method->setAccessible(true);

        $concatFile = $method->invoke($engine, $channel->fresh());
        $content = file_get_contents($concatFile);

        $this->assertSame(720, count(explode("file '" . $videoA . "'", $content)) - 1);
        $this->assertSame(720, count(explode("file '" . $videoB . "'", $content)) - 1);
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
            'is_active'      => true,
            'stream_status'  => 'live',
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
    public function schedule_uses_current_time_when_no_anchor_and_never_started(): void
    {
        $channel = $this->createTvChannel();
        // last_live_at is null — channel never started

        PlaylistItem::factory()
            ->ordered(0)
            ->create(['channel_id' => $channel->id, 'duration' => 60.0]);

        $engine = app(TvPlayoutEngine::class);
        $engine->recalculateSchedule($channel);

        $item = $channel->playlistItems()->first();
        $now = now();

        $this->assertTrue(
            $item->scheduled_start->diffInSeconds($now) <= 2,
            'Scheduled start should be within 2 seconds of now when channel has never started'
        );
    }
}
