<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Models\DvrSegment;
use App\Services\DVRService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DVRServiceTest extends TestCase
{
    use RefreshDatabase;

    private DVRService $dvr;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::create([
            'name'             => 'DVRTest',
            'slug'             => 'dvrtest-' . fake()->randomNumber(3),
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

        $this->dvr = app(DVRService::class);
    }

    /** @test */
    public function it_counts_zero_segments_when_none_exist(): void
    {
        $this->assertSame(0, $this->dvr->segmentCount($this->channel));
    }

    /** @test */
    public function it_has_no_segments_initially(): void
    {
        $this->assertFalse($this->dvr->hasSegments($this->channel));
    }

    /** @test */
    public function it_returns_zero_duration_for_empty_dvr(): void
    {
        $this->assertSame(0.0, $this->dvr->totalDuration($this->channel));
    }

    /** @test */
    public function it_returns_zero_size_for_empty_dvr(): void
    {
        $this->assertSame(0, $this->dvr->totalSize($this->channel));
    }

    /** @test */
    public function it_returns_zero_buffer_percent_for_empty_dvr(): void
    {
        $this->assertSame(0, $this->dvr->bufferPercent($this->channel));
    }

    /** @test */
    public function it_calculates_correct_duration_from_segments(): void
    {
        DvrSegment::create([
            'channel_id'   => $this->channel->id,
            'filename'     => 'seg_00001.ts',
            'filepath'     => '/tmp/test/seg_00001.ts',
            'duration'     => 6.0,
            'sequence'     => 1,
            'filesize'     => 102400,
            'recorded_at'  => now(),
            'is_available' => true,
        ]);

        DvrSegment::create([
            'channel_id'   => $this->channel->id,
            'filename'     => 'seg_00002.ts',
            'filepath'     => '/tmp/test/seg_00002.ts',
            'duration'     => 6.0,
            'sequence'     => 2,
            'filesize'     => 204800,
            'recorded_at'  => now(),
            'is_available' => true,
        ]);

        $this->assertSame(12.0, $this->dvr->totalDuration($this->channel));
        $this->assertSame(307200, $this->dvr->totalSize($this->channel));
        $this->assertSame(2, $this->dvr->segmentCount($this->channel));
    }

    /** @test */
    public function it_excludes_unavailable_segments_from_totals(): void
    {
        DvrSegment::create([
            'channel_id'   => $this->channel->id,
            'filename'     => 'seg_00001.ts',
            'filepath'     => '/tmp/test/seg_00001.ts',
            'duration'     => 6.0,
            'sequence'     => 1,
            'filesize'     => 102400,
            'recorded_at'  => now(),
            'is_available' => false,
        ]);

        $this->assertSame(0.0, $this->dvr->totalDuration($this->channel));
    }

    /** @test */
    public function purge_removes_all_segments(): void
    {
        DvrSegment::create([
            'channel_id'   => $this->channel->id,
            'filename'     => 'seg_00001.ts',
            'filepath'     => '/tmp/test/seg_00001.ts',
            'duration'     => 6.0,
            'sequence'     => 1,
            'filesize'     => 102400,
            'recorded_at'  => now(),
            'is_available' => true,
        ]);

        $deleted = $this->dvr->purgeAll($this->channel);

        $this->assertGreaterThan(0, $deleted);
        $this->assertSame(0, $this->dvr->segmentCount($this->channel));
    }
}
