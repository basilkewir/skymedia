<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\PlaylistItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TvPlayoutControllerTest extends TestCase
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
    public function index_renders_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('channels.playout', $this->channel))
            ->assertStatus(200)
            ->assertInertia(fn($page) => $page->component('Channels/TvPlayout'));
    }

    /** @test */
    public function start_returns_422_when_no_playlist_items(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('channels.playout.start', $this->channel))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function stop_returns_json_response(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('channels.playout.stop', $this->channel))
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    /** @test */
    public function status_returns_json_response(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('channels.playout.status', $this->channel))
            ->assertOk()
            ->assertJsonStructure(['is_running', 'playout_status', 'playout_pid', 'current_item']);
    }

    /** @test */
    public function add_youtube_validates_url_field(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        $this->actingAs($this->admin)
            ->post(route('channels.playout.youtube', $this->channel), [
                'youtube_url' => '',
            ])
            ->assertSessionHasErrors(['youtube_url']);
    }

    /** @test */
    public function add_youtube_rejects_invalid_url(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        $this->actingAs($this->admin)
            ->post(route('channels.playout.youtube', $this->channel), [
                'youtube_url' => 'https://vimeo.com/12345',
            ])
            ->assertSessionHasErrors(['youtube_url']);
    }

    /** @test */
    public function add_youtube_accepts_standard_youtube_url(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'dQw4w9WgXcQ',
                    'snippet' => [
                        'title' => 'Test Video',
                        'channelTitle' => 'Test Channel',
                        'thumbnails' => ['high' => ['url' => 'https://example.com/thumb.jpg']],
                    ],
                    'contentDetails' => ['duration' => 'PT3M20S'],
                ]],
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('channels.playout.youtube', $this->channel), [
                'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('playlist_items', [
            'channel_id' => $this->channel->id,
            'filepath'   => 'youtube:dQw4w9WgXcQ',
            'title'      => 'Test Video',
        ]);
    }

    /** @test */
    public function add_youtube_accepts_short_youtube_url(): void
    {
        Setting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key']);

        Http::fake([
            'googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'abc12345678',
                    'snippet' => [
                        'title' => 'Short Video',
                        'channelTitle' => 'Ch',
                        'thumbnails' => [],
                    ],
                    'contentDetails' => ['duration' => 'PT1M'],
                ]],
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('channels.playout.youtube', $this->channel), [
                'youtube_url' => 'https://youtu.be/abc12345678',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('playlist_items', [
            'filepath' => 'youtube:abc12345678',
        ]);
    }

    /** @test */
    public function destroy_item_removes_from_database(): void
    {
        $item = PlaylistItem::factory()->create(['channel_id' => $this->channel->id]);

        $this->actingAs($this->admin)
            ->delete(route('channels.playout.items.destroy', [$this->channel, $item]))
            ->assertRedirect();

        $this->assertDatabaseMissing('playlist_items', ['id' => $item->id]);
    }

    /** @test */
    public function reorder_updates_sort_order(): void
    {
        $item1 = PlaylistItem::factory()->ordered(1)->create(['channel_id' => $this->channel->id]);
        $item2 = PlaylistItem::factory()->ordered(2)->create(['channel_id' => $this->channel->id]);
        $item3 = PlaylistItem::factory()->ordered(3)->create(['channel_id' => $this->channel->id]);

        $this->actingAs($this->admin)
            ->postJson(route('channels.playout.reorder', $this->channel), [
                'items' => [
                    ['id' => $item3->id, 'sort_order' => 1],
                    ['id' => $item1->id, 'sort_order' => 2],
                    ['id' => $item2->id, 'sort_order' => 3],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, $item3->fresh()->sort_order);
        $this->assertSame(2, $item1->fresh()->sort_order);
        $this->assertSame(3, $item2->fresh()->sort_order);
    }

    /** @test */
    public function update_ticker_saves_text(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('channels.playout.ticker', $this->channel), [
                'ticker' => 'Breaking: Important news here',
            ])
            ->assertOk();

        $this->channel->refresh();
        $this->assertSame('Breaking: Important news here', $this->channel->ticker_text);
    }

    /** @test */
    public function toggle_ticker_toggles_enabled_flag(): void
    {
        $this->channel->update(['ticker_enabled' => false]);

        $this->actingAs($this->admin)
            ->postJson(route('channels.playout.toggle-ticker', $this->channel))
            ->assertOk();

        $this->assertTrue($this->channel->fresh()->ticker_enabled);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_playout(): void
    {
        $this->get(route('channels.playout', $this->channel))
            ->assertRedirect('/login');
    }
}
