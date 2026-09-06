<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PlaylistItem;
use Tests\TestCase;

class PlaylistItemModelTest extends TestCase
{
    /** @test */
    public function it_formats_duration_correctly(): void
    {
        $item = new PlaylistItem(['duration' => 3723.45]);
        $this->assertSame('01:02:03.45', $item->formatted_duration);
    }

    /** @test */
    public function it_formats_duration_under_one_hour(): void
    {
        $item = new PlaylistItem(['duration' => 125.99]);
        $this->assertSame('00:02:05.99', $item->formatted_duration);
    }

    /** @test */
    public function it_formats_duration_zero(): void
    {
        $item = new PlaylistItem(['duration' => 0.0]);
        $this->assertSame('00:00:00.00', $item->formatted_duration);
    }

    /** @test */
    public function it_formats_duration_over_ten_hours(): void
    {
        $item = new PlaylistItem(['duration' => 36000.0]);
        $this->assertSame('10:00:00.00', $item->formatted_duration);
    }

    /** @test */
    public function it_formats_scheduled_start(): void
    {
        $item = new PlaylistItem(['scheduled_start' => '2026-09-06 14:30:00']);
        $this->assertSame('14:30:00', $item->formatted_start);
    }

    /** @test */
    public function it_formats_scheduled_end(): void
    {
        $item = new PlaylistItem(['scheduled_end' => '2026-09-06 15:00:00']);
        $this->assertSame('15:00:00', $item->formatted_end);
    }

    /** @test */
    public function it_returns_null_when_no_scheduled_start(): void
    {
        $item = new PlaylistItem(['scheduled_start' => null]);
        $this->assertNull($item->formatted_start);
    }

    /** @test */
    public function it_identifies_youtube_items(): void
    {
        $item = new PlaylistItem(['filepath' => 'youtube:dQw4w9WgXcQ']);
        $this->assertTrue($item->isYouTube());
    }

    /** @test */
    public function it_identifies_non_youtube_items(): void
    {
        $item = new PlaylistItem(['filepath' => '/var/media/video.mp4']);
        $this->assertFalse($item->isYouTube());
    }

    /** @test */
    public function it_extracts_youtube_id_from_prefixed_filepath(): void
    {
        $item = new PlaylistItem(['filepath' => 'youtube:dQw4w9WgXcQ']);
        $this->assertSame('dQw4w9WgXcQ', $item->youtube_id);
    }

    /** @test */
    public function it_returns_null_youtube_id_for_local_files(): void
    {
        $item = new PlaylistItem(['filepath' => '/var/media/video.mp4']);
        $this->assertNull($item->youtube_id);
    }

    /** @test */
    public function parse_youtube_id_returns_id_from_prefixed_string(): void
    {
        $this->assertSame('dQw4w9WgXcQ', PlaylistItem::parseYouTubeId('youtube:dQw4w9WgXcQ'));
    }

    /** @test */
    public function parse_youtube_id_returns_null_for_local_path(): void
    {
        $this->assertNull(PlaylistItem::parseYouTubeId('/var/media/video.mp4'));
    }

    /** @test */
    public function parse_youtube_id_returns_null_for_empty_string(): void
    {
        $this->assertNull(PlaylistItem::parseYouTubeId(''));
    }

    /** @test */
    public function parse_youtube_id_returns_null_for_just_prefix(): void
    {
        $this->assertNull(PlaylistItem::parseYouTubeId('youtube:'));
    }

    /** @test */
    public function it_belongs_to_a_channel(): void
    {
        $item = PlaylistItem::factory()->create();
        $this->assertNotNull($item->channel);
    }
}
