<?php

namespace Tests\Feature;

use App\Jobs\DownloadMediaToMp4;
use App\Models\Channel;
use App\Models\ChannelMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentMediaDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $otherUser;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin       = User::factory()->create(['is_admin' => true]);
        $this->otherUser   = User::factory()->create(['is_admin' => false]);
        $this->channel     = Channel::factory()->create([
            'source_type' => 'hls',
            'user_id'     => $this->admin->id,
        ]);
    }

    /** @test */
    public function download_from_url_creates_media_record_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.content.download', $this->channel), [
                'url' => 'https://hls-cdn77.xvideos-cdn.com/kMAkPTZ6wfCcmR-YX2r55Q==,1789090035/cf6be375-192d-4984-98b9-de66af4f54a3/0/hls.m3u8',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json();
        $this->assertIsInt($data['media_id']);

        $media = ChannelMedia::find($data['media_id']);
        $this->assertNotNull($media);
        $this->assertEquals('vod', $media->type);
        $this->assertEquals('hls.m3u8', $media->name);
        $this->assertEquals('https://hls-cdn77.xvideos-cdn.com/kMAkPTZ6wfCcmR-YX2r55Q==,1789090035/cf6be375-192d-4984-98b9-de66af4f54a3/0/hls.m3u8', $media->filepath);
        $this->assertFalse($media->is_active);
        $this->assertEquals(0, $media->filesize);
        $this->assertEquals('application/x-downloading', $media->mime_type);

        Queue::assertPushed(DownloadMediaToMp4::class, function (DownloadMediaToMp4 $job) use ($media) {
            return $job->mediaId === $media->id
                && $job->url === 'https://hls-cdn77.xvideos-cdn.com/kMAkPTZ6wfCcmR-YX2r55Q==,1789090035/cf6be375-192d-4984-98b9-de66af4f54a3/0/hls.m3u8'
                && str_ends_with($job->outputPath, '.mp4');
        });
    }

    /** @test */
    public function download_from_url_accepts_custom_title(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.content.download', $this->channel), [
                'url'   => 'https://example.com/video.m3u8',
                'title' => 'My Cool Video',
            ]);

        $response->assertOk();

        $media = ChannelMedia::where('name', 'My Cool Video')->first();
        $this->assertNotNull($media);
        $this->assertEquals('My Cool Video', $media->name);
    }

    /** @test */
    public function download_from_url_rejects_non_http_urls(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.content.download', $this->channel), [
                'url' => 'ftp://example.com/video.mp4',
            ]);

        $response->assertStatus(422);
        $this->assertEquals(0, ChannelMedia::count());
    }

    /** @test */
    public function download_from_url_rejects_duplicate_url(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->postJson(route('channels.content.download', $this->channel), [
                'url' => 'https://example.com/video.m3u8',
            ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.content.download', $this->channel), [
                'url' => 'https://example.com/video.m3u8',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertEquals(1, ChannelMedia::count());
    }

    /** @test */
    public function download_from_url_requires_access(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->otherUser)
            ->postJson(route('channels.content.download', $this->channel), [
                'url' => 'https://example.com/video.m3u8',
            ]);

        $response->assertStatus(403);
        $this->assertEquals(0, ChannelMedia::count());
    }

    /** @test */
    public function download_from_url_rejects_when_storage_quota_full(): void
    {
        Queue::fake();

        $this->channel->update([
            'storage_quota_bytes' => 1000,
            'storage_used_bytes'  => 1000,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.content.download', $this->channel), [
                'url' => 'https://example.com/video.m3u8',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'Channel storage quota is full. Remove some media to free up space.']);

        $this->assertEquals(0, ChannelMedia::count());
    }

    /** @test */
    public function preview_url_returns_title_and_playable_status(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.content.preview-url', $this->channel), [
                'url' => 'https://example.com/my-video.m3u8',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'title'   => 'my-video.m3u8',
                'type'    => 'hls',
            ])
            ->assertJsonStructure(['duration', 'playable']);
    }

    /** @test */
    public function preview_url_rejects_non_http_urls(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.content.preview-url', $this->channel), [
                'url' => 'rtmp://example.com/stream',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function download_status_returns_empty_when_no_downloads(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('channels.content.download-status', $this->channel));

        $response->assertOk()
            ->assertJson(['statuses' => []]);
    }

    /** @test */
    public function download_status_shows_downloading_for_url_items(): void
    {
        Queue::fake();

        $channel = $this->channel;
        $dir = $channel->dvr_directory . '/content';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $media = ChannelMedia::create([
            'channel_id'  => $channel->id,
            'type'        => 'vod',
            'name'        => 'test-download',
            'filepath'    => 'https://example.com/video.m3u8',
            'mime_type'   => 'application/x-downloading',
            'filesize'    => 0,
            'sort_order'  => 1,
            'is_active'   => false,
        ]);
        file_put_contents($dir . '/download_' . $media->id . '.lock', '1234');

        $response = $this->actingAs($this->admin)
            ->getJson(route('channels.content.download-status', $channel));

        $response->assertOk()
            ->assertJsonFragment([
                'statuses' => [$media->id => 'downloading'],
            ]);

        @unlink($dir . '/download_' . $media->id . '.lock');
    }

    /** @test */
    public function download_status_shows_queued_when_no_lock_file(): void
    {
        Queue::fake();

        ChannelMedia::create([
            'channel_id'  => $this->channel->id,
            'type'        => 'vod',
            'name'        => 'queued-item',
            'filepath'    => 'https://example.com/queued.m3u8',
            'mime_type'   => 'application/x-downloading',
            'filesize'    => 0,
            'sort_order'  => 1,
            'is_active'   => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('channels.content.download-status', $this->channel));

        $response->assertOk()
            ->assertJsonStructure(['statuses']);

        $this->assertArrayHasKey('statuses', $response->json());
    }
}
