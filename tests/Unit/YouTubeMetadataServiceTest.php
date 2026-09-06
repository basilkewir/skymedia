<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\YouTubeMetadataService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeMetadataServiceTest extends TestCase
{
    private YouTubeMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(YouTubeMetadataService::class);
    }

    // ── extractVideoId ──────────────────────────────────────────────────

    /** @test */
    public function extract_video_id_from_standard_url(): void
    {
        $id = YouTubeMetadataService::extractVideoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertSame('dQw4w9WgXcQ', $id);
    }

    /** @test */
    public function extract_video_id_from_short_url(): void
    {
        $id = YouTubeMetadataService::extractVideoId('https://youtu.be/dQw4w9WgXcQ');
        $this->assertSame('dQw4w9WgXcQ', $id);
    }

    /** @test */
    public function extract_video_id_from_embed_url(): void
    {
        $id = YouTubeMetadataService::extractVideoId('https://www.youtube.com/embed/dQw4w9WgXcQ');
        $this->assertSame('dQw4w9WgXcQ', $id);
    }

    /** @test */
    public function extract_video_id_from_v_url(): void
    {
        $id = YouTubeMetadataService::extractVideoId('https://www.youtube.com/v/dQw4w9WgXcQ');
        $this->assertSame('dQw4w9WgXcQ', $id);
    }

    /** @test */
    public function extract_video_id_from_shorts_url(): void
    {
        $id = YouTubeMetadataService::extractVideoId('https://www.youtube.com/shorts/dQw4w9WgXcQ');
        $this->assertSame('dQw4w9WgXcQ', $id);
    }

    /** @test */
    public function extract_video_id_from_url_with_extra_params(): void
    {
        $id = YouTubeMetadataService::extractVideoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf');
        $this->assertSame('dQw4w9WgXcQ', $id);
    }

    /** @test */
    public function extract_video_id_returns_null_for_invalid_url(): void
    {
        $this->assertNull(YouTubeMetadataService::extractVideoId('https://example.com/video'));
    }

    /** @test */
    public function extract_video_id_returns_null_for_empty_string(): void
    {
        $this->assertNull(YouTubeMetadataService::extractVideoId(''));
    }

    /** @test */
    public function extract_video_id_returns_null_for_random_text(): void
    {
        $this->assertNull(YouTubeMetadataService::extractVideoId('not a url'));
    }

    /** @test */
    public function extract_video_id_handles_vimeo_url(): void
    {
        $this->assertNull(YouTubeMetadataService::extractVideoId('https://vimeo.com/123456789'));
    }

    // ── getVideoDetails ─────────────────────────────────────────────────

    /** @test */
    public function get_video_details_throws_when_no_api_key(): void
    {
        Setting::where('key', 'youtube_api_key')->delete();
        config(['skymedia.youtube_api_key' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('YouTube API key not configured');

        $this->service->getVideoDetails('dQw4w9WgXcQ');
    }

    /** @test */
    public function get_video_details_uses_db_key_over_config(): void
    {
        config(['skymedia.youtube_api_key' => 'config-key']);
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'db-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'dQw4w9WgXcQ',
                    'snippet' => ['title' => 'Test', 'channelTitle' => 'Ch', 'thumbnails' => []],
                    'contentDetails' => ['duration' => 'PT3M20S'],
                ]],
            ]),
        ]);

        $result = $this->service->getVideoDetails('dQw4w9WgXcQ');

        $this->assertSame('Test', $result['title']);
    }

    /** @test */
    public function get_video_details_parses_iso8601_duration(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'test123',
                    'snippet' => ['title' => 'Video', 'channelTitle' => 'Ch', 'thumbnails' => []],
                    'contentDetails' => ['duration' => 'PT1H2M3S'],
                ]],
            ]),
        ]);

        $result = $this->service->getVideoDetails('test123');

        // 1h = 3600, 2m = 120, 3s = 3 → 3723
        $this->assertSame(3723.0, $result['duration']);
    }

    /** @test */
    public function get_video_details_parses_short_duration(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'short1',
                    'snippet' => ['title' => 'Short', 'channelTitle' => 'Ch', 'thumbnails' => []],
                    'contentDetails' => ['duration' => 'PT45S'],
                ]],
            ]),
        ]);

        $result = $this->service->getVideoDetails('short1');

        $this->assertSame(45.0, $result['duration']);
    }

    /** @test */
    public function get_video_details_throws_on_api_error(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'invalid-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'error' => [
                    'code'    => 403,
                    'message' => 'The request cannot be completed because you have exceeded your quota.',
                ],
            ], 403),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('YouTube API error');

        $this->service->getVideoDetails('dQw4w9WgXcQ');
    }

    /** @test */
    public function get_video_details_throws_when_video_not_found(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('YouTube video not found');

        $this->service->getVideoDetails('nonexistent');
    }

    /** @test */
    public function get_video_details_returns_thumbnail(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'thumb1',
                    'snippet' => [
                        'title' => 'Test',
                        'channelTitle' => 'Ch',
                        'thumbnails' => [
                            'high'   => ['url' => 'https://i.ytimg.com/vi/thumb1/hqdefault.jpg'],
                            'default' => ['url' => 'https://i.ytimg.com/vi/thumb1/default.jpg'],
                        ],
                    ],
                    'contentDetails' => ['duration' => 'PT10S'],
                ]],
            ]),
        ]);

        $result = $this->service->getVideoDetails('thumb1');

        $this->assertSame('https://i.ytimg.com/vi/thumb1/hqdefault.jpg', $result['thumbnail']);
    }

    /** @test */
    public function get_video_details_falls_back_to_default_thumbnail(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'thumb2',
                    'snippet' => [
                        'title' => 'Test',
                        'channelTitle' => 'Ch',
                        'thumbnails' => [
                            'default' => ['url' => 'https://i.ytimg.com/vi/thumb2/default.jpg'],
                        ],
                    ],
                    'contentDetails' => ['duration' => 'PT10S'],
                ]],
            ]),
        ]);

        $result = $this->service->getVideoDetails('thumb2');

        $this->assertSame('https://i.ytimg.com/vi/thumb2/default.jpg', $result['thumbnail']);
    }

    /** @test */
    public function get_video_details_returns_empty_thumbnail_when_none(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'nothumb',
                    'snippet' => [
                        'title' => 'Test',
                        'channelTitle' => 'Ch',
                        'thumbnails' => [],
                    ],
                    'contentDetails' => ['duration' => 'PT10S'],
                ]],
            ]),
        ]);

        $result = $this->service->getVideoDetails('nothumb');

        $this->assertSame('', $result['thumbnail']);
    }
}
