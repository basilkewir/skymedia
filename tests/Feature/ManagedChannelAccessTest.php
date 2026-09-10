<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManagedChannelAccessTest extends TestCase
{
    public function test_owner_sees_managed_channel_without_forwarding_configuration(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'source_type' => 'rtmp',
            'ingest_mode' => 'push',
            'ingest_port' => 20010,
            'rtmp_input_key' => 'publisher-secret',
            'push_url' => 'rtmp://forwarding.example/live',
            'push_stream_key' => 'forwarding-secret',
        ]);

        $this->actingAs($user)
            ->get(route('channels.show', $channel))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Channels/Show')
                ->where('isAdmin', false)
                ->where('channel.published_ingest_url', fn ($url) => str_contains($url, 'publisher-secret'))
                ->missing('channel.push_url')
                ->missing('channel.push_stream_key'));
    }

    public function test_owner_cannot_open_or_control_forwarding_configuration(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'source_type' => 'srt',
            'ingest_mode' => 'push',
            'ingest_port' => 30010,
        ]);

        $this->actingAs($user)->get(route('channels.edit', $channel))->assertForbidden();
        $this->actingAs($user)->post(route('channels.push.start', $channel))->assertForbidden();
        $this->actingAs($user)->get(route('push.log', $channel))->assertForbidden();
    }

    public function test_other_users_cannot_access_a_managed_channel(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        $channel = Channel::factory()->create([
            'user_id' => $owner->id,
            'source_type' => 'rtmp',
            'ingest_mode' => 'push',
            'ingest_port' => 20011,
        ]);

        $this->actingAs($other)->get(route('channels.show', $channel))->assertForbidden();
    }

    public function test_manager_can_open_content_manager_but_not_system_admin_pages(): void
    {
        $manager = User::factory()->create(['is_admin' => false]);
        $channel = Channel::factory()->create(['user_id' => $manager->id, 'source_type' => 'rtmp', 'ingest_mode' => 'push', 'ingest_port' => 20012]);

        $this->actingAs($manager)->get(route('channels.content', $channel))->assertOk();
        $this->actingAs($manager)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('users.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('logs.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('dvr.index'))->assertForbidden();
    }

    public function test_editing_channel_preserves_push_settings(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'source_type' => 'rtmp',
            'push_url' => 'rtmp://forwarding.example/live',
            'push_stream_key' => 'secret-key-123',
            'push_username' => 'pushuser',
            'push_password' => 'pushpass',
            'rtmp_input_key' => 'ingest-key-abc',
        ]);

        // The edit page must expose push fields so they round-trip on save
        $this->actingAs($user)
            ->get(route('channels.edit', $channel))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('channel.push_url', 'rtmp://forwarding.example/live')
                ->where('channel.push_stream_key', 'secret-key-123')
                ->where('channel.rtmp_input_key', 'ingest-key-abc'));

        $this->actingAs($user)
            ->put(route('channels.update', $channel), [
                'name' => 'Renamed Channel',
                'source_type' => 'rtmp',
                'source_url' => $channel->source_url,
                'push_url' => 'rtmp://forwarding.example/live',
                'push_stream_key' => 'secret-key-123',
                'push_username' => 'pushuser',
                'push_password' => 'pushpass',
            ])
            ->assertRedirect();

        $channel->refresh();
        $this->assertSame('secret-key-123', $channel->push_stream_key);
        $this->assertSame('pushuser', $channel->push_username);
        $this->assertSame('pushpass', $channel->push_password);
        $this->assertSame('ingest-key-abc', $channel->rtmp_input_key);
    }
}
