<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddUrlPlaylistTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->channel = Channel::factory()->create([
            'source_type' => 'tv_playout',
            'user_id'     => $this->admin->id,
        ]);
    }

    /** @test */
    public function addUrl_creates_item_with_is_active_true(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.playout.url', $this->channel), [
                'url' => 'https://example.com/test.mp4',
            ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertTrue($data['success']);
        $this->assertIsInt($data['item_id']);

        $item = PlaylistItem::find($data['item_id']);
        $this->assertNotNull($item);
        $this->assertEquals(1, $item->is_active, 'is_active should be 1 in database');
        $this->assertEquals($this->channel->id, $item->channel_id);
        $this->assertNotEmpty($item->title);
        $this->assertGreaterThan(0, $item->duration);
        $this->assertIsInt($item->sort_order);
    }

    /** @test */
    public function addUrl_item_appears_in_playlist_index_query(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('channels.playout.url', $this->channel), [
                'url' => 'https://example.com/test.mp4',
            ])
            ->assertOk();

        // Query the playlist items the same way the controller does
        $items = $this->channel->playlistItems()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(1, $items);
        $this->assertEquals('https://example.com/test.mp4', $items->first()->filepath);
    }

    /** @test */
    public function addUrl_item_shows_in_index_response(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('channels.playout.url', $this->channel), [
                'url' => 'https://example.com/test.mp4',
            ])
            ->assertOk();

        // Reload channel to get fresh data
        $this->channel->refresh();

        // Fetch the index page
        $indexResponse = $this->actingAs($this->admin)
            ->get(route('channels.playout', $this->channel));

        $indexResponse->assertStatus(200);
        $indexResponse->assertInertia(fn($page) => $page
            ->component('Channels/TvPlayout')
            ->has('items', 1)
        );
    }

    /** @test */
    public function addUrl_with_brackets_in_url_creates_item(): void
    {
        $url = 'https://16.static.gfrdaseazzs.com/token/download/tempuser/fc029326bd22b62a/Monica.2026.540p.x265.AAC.[9jaRocks.Com].mkv?download_token=abc123';

        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.playout.url', $this->channel), [
                'url' => $url,
            ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertTrue($data['success']);

        $item = PlaylistItem::find($data['item_id']);
        $this->assertNotNull($item);
        $this->assertEquals($url, $item->filepath);
        $this->assertEquals(1, $item->is_active);
    }

    /** @test */
    public function addUrl_with_hls_stream_creates_item(): void
    {
        $url = 'https://hls-gcore.xvideos-cdn.com/UUWCmGtGWHExfp-mOqGS_w==,1789049179/0ad9cde0-1587-4bf8-8edd-3988ae4df253/0/hls.m3u8';

        $response = $this->actingAs($this->admin)
            ->postJson(route('channels.playout.url', $this->channel), [
                'url' => $url,
            ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertTrue($data['success']);

        $item = PlaylistItem::find($data['item_id']);
        $this->assertNotNull($item);
        $this->assertEquals($url, $item->filepath);
        $this->assertEquals(1, $item->is_active);
        $this->assertEquals(7200.0, $item->duration, 'HLS streams should get 2h default duration');
    }
}
