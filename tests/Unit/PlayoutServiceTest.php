<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Services\DVRService;
use App\Services\PlayoutService;
use Mockery;
use Tests\TestCase;

class PlayoutServiceTest extends TestCase
{
    private PlayoutService $playout;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::create([
            'name'             => 'PlayoutTest',
            'slug'             => 'playout-test-' . fake()->randomNumber(3),
            'source_type'      => 'hls',
            'source_url'       => 'https://example.com/stream.m3u8',
            'push_protocol'    => 'rtmp',
            'push_url'         => 'rtmp://localhost/live',
            'push_stream_key'  => 'k',
            'push_video_codec' => 'copy',
            'push_audio_codec' => 'aac',
            'dvr_duration'     => 3600,
            'segment_duration' => 6,
            'record_duration'  => 0,
            'check_interval'   => 5,
            'max_retries'      => 3,
        ]);

        $this->playout = app(PlayoutService::class);
    }

    /** @test */
    public function it_returns_live_m3u8_for_output_playlist_by_default(): void
    {
        $path = $this->playout->outputPlaylist($this->channel);
        $this->assertStringContainsString('live.m3u8', $path);
    }

    /** @test */
    public function it_returns_playout_m3u8_when_in_fallback_mode(): void
    {
        $this->channel->update(['playout_status' => 'fallback']);

        $path = $this->playout->outputPlaylist($this->channel->fresh());
        $this->assertStringContainsString('playout.m3u8', $path);
    }

    /** @test */
    public function it_has_no_fallback_when_none_exists(): void
    {
        $this->assertFalse($this->playout->hasFallback($this->channel));
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
}
