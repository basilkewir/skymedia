<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Services\PlayoutService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PlayoutServiceTest extends TestCase
{
    private PlayoutService $playout;

    private Channel $channel;

    private string $dvrBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dvrBase = sys_get_temp_dir() . '/skymedia_playout_test_' . uniqid();
        config(['skymedia.dvr_base_path' => $this->dvrBase]);

        $this->channel = Channel::create([
            'name' => 'PlayoutTest',
            'slug' => 'playout-test-' . fake()->randomNumber(3),
            'source_type' => 'hls',
            'source_url' => 'https://example.com/stream.m3u8',
            'push_protocol' => 'rtmp',
            'push_url' => 'rtmp://localhost/live',
            'push_stream_key' => 'k',
            'push_video_codec' => 'copy',
            'push_audio_codec' => 'aac',
            'dvr_duration' => 3600,
            'segment_duration' => 6,
            'record_duration' => 0,
            'check_interval' => 5,
            'max_retries' => 3,
        ]);

        $this->playout = app(PlayoutService::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dvrBase)) {
            File::deleteDirectory($this->dvrBase);
        }

        parent::tearDown();
    }

    /** @test */
    public function output_playlist_always_returns_output_m3u8(): void
    {
        $path = $this->playout->outputPlaylist($this->channel);
        // outputPlaylist always returns output.m3u8 (symlink points to the active playlist)
        $this->assertStringContainsString('output.m3u8', $path);
        $this->assertStringNotContainsString('live.m3u8', $path);
    }

    /** @test */
    public function output_playlist_is_stable_in_fallback_mode(): void
    {
        $this->channel->update(['playout_status' => 'fallback']);

        $path = $this->playout->outputPlaylist($this->channel->fresh());
        // The returned path is still output.m3u8; only the symlink target changes.
        $this->assertStringContainsString('output.m3u8', $path);
    }

    /** @test */
    public function it_has_no_fallback_when_no_media_exists(): void
    {
        // Ensure the DVR directory is empty so no recordings, segments, or slate exist.
        $dvrDir = $this->channel->dvr_directory;
        if (is_dir($dvrDir)) {
            File::deleteDirectory($dvrDir);
        }

        $this->assertFalse($this->playout->hasFallback($this->channel->fresh()));
    }

    /** @test */
    public function fallback_process_is_not_running_initially(): void
    {
        $this->assertFalse($this->playout->isFallbackRunning($this->channel));
    }

    /** @test */
    public function switch_to_live_cleans_up_fallback(): void
    {
        $this->playout->switchToLive($this->channel);
        $ch = $this->channel->fresh();

        $this->assertSame('live', $ch->playout_status);
        $this->assertNull($ch->playout_pid);
    }

    /** @test */
    public function switch_to_live_creates_symlink_even_before_live_playlist_exists(): void
    {
        $this->playout->switchToLive($this->channel);

        $link = $this->playout->outputPlaylist($this->channel);
        $this->assertTrue(is_link($link));
        $this->assertSame('live.m3u8', readlink($link));
    }

    /** @test */
    public function is_live_output_detects_symlink_target(): void
    {
        $link = $this->playout->outputPlaylist($this->channel);
        mkdir(dirname($link), 0755, true);

        $this->assertFalse($this->playout->isLiveOutput($this->channel));

        symlink('live.m3u8', $link);
        $this->assertTrue($this->playout->isLiveOutput($this->channel));

        @unlink($link);
        symlink('playout.m3u8', $link);
        $this->assertFalse($this->playout->isLiveOutput($this->channel));
    }
}
