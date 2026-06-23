<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use Tests\TestCase;

class ChannelModelTest extends TestCase
{
    /** @test */
    public function it_fills_mass_assignable_attributes(): void
    {
        $channel = Channel::create([
            'name' => 'Test Channel',
            'slug' => 'test-channel',
            'source_type' => 'hls',
            'source_url' => 'https://example.com/stream.m3u8',
            'push_protocol' => 'rtmp',
            'push_url' => 'rtmp://live.example.com/live',
            'push_stream_key' => 'stream-key-123',
            'push_video_codec' => 'copy',
            'push_audio_codec' => 'aac',
            'dvr_duration' => 3600,
            'segment_duration' => 6,
            'record_duration' => 300,
            'check_interval' => 5,
            'max_retries' => 3,
        ]);

        $this->assertSame('Test Channel', $channel->name);
        $this->assertSame('test-channel', $channel->slug);
        $this->assertSame('hls', $channel->source_type);
        $this->assertFalse($channel->fresh()->is_active);
        $this->assertSame('idle', $channel->fresh()->stream_status);
    }

    /** @test */
    public function it_generates_push_target_from_url_and_key(): void
    {
        $channel = new Channel([
            'push_url' => 'rtmp://live.example.com/live',
            'push_stream_key' => 'my-key',
        ]);

        $this->assertSame('rtmp://live.example.com/live/my-key', $channel->push_target);
    }

    /** @test */
    public function it_computes_dvr_directory(): void
    {
        $channel = Channel::create([
            'name' => 'DirTest',
            'slug' => 'dir-test',
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

        $expected = config('skymedia.dvr_base_path', storage_path('app/dvr')) . '/' . $channel->id;
        $this->assertSame($expected, $channel->dvr_directory);
    }

    /** @test */
    public function it_formats_dvr_window_label(): void
    {
        $channel = new Channel(['dvr_duration' => 3660]); // 1h 1m
        $this->assertSame('1h 1m', $channel->dvr_window_label);

        $channel2 = new Channel(['dvr_duration' => 600]); // 10m
        $this->assertSame('10m', $channel2->dvr_window_label);
    }

    /** @test */
    public function it_formats_record_duration_label(): void
    {
        $channel = new Channel(['record_duration' => 0]);
        $this->assertSame('Disabled', $channel->record_duration_label);

        $channel2 = new Channel(['record_duration' => 1800]); // 30m
        $this->assertSame('30m per file', $channel2->record_duration_label);
    }

    /** @test */
    public function it_resets_retries(): void
    {
        $channel = Channel::create([
            'name' => 'RetryTest',
            'slug' => 'retry-test',
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
            'retry_count' => 5,
            'last_error' => 'Some error',
        ]);

        $channel->resetRetries();
        $channel->refresh();

        $this->assertSame(0, $channel->retry_count);
        $this->assertNull($channel->last_error);
    }

    /** @test */
    public function it_increments_retries(): void
    {
        $channel = Channel::create([
            'name' => 'IncrTest',
            'slug' => 'incr-test',
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
            'max_retries' => 5,
        ]);

        $channel->incrementRetry('Test error');
        $channel->refresh();

        $this->assertSame(1, $channel->retry_count);
        $this->assertSame('Test error', $channel->last_error);
    }
}
