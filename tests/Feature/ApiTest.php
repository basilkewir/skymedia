<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.database', 'ok');
    }

    /** @test */
    public function liveness_endpoint_returns_alive(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'alive');
    }
}

class ChannelApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_lists_channels(): void
    {
        Channel::factory()->count(3)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/channels');

        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    /** @test */
    public function it_returns_status_all(): void
    {
        Channel::factory()->active()->create(['name' => 'Live Channel']);
        Channel::factory()->create(['name' => 'Idle Channel']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/channels/status-all');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
    }

    /** @test */
    public function it_returns_single_channel_status(): void
    {
        $channel = Channel::factory()->active()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/channels/{$channel->id}/status");

        $response->assertStatus(200);
        $response->assertJsonPath('id', $channel->id);
        $response->assertJsonPath('name', $channel->name);
    }

    /** @test */
    public function it_returns_stats(): void
    {
        Channel::factory()->active()->count(2)->create();
        Channel::factory()->count(3)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('total', 5);
        $response->assertJsonPath('live', 2);
        $response->assertJsonPath('active', 2);
    }

    /** @test */
    public function it_requires_authentication(): void
    {
        $this->getJson('/api/channels')->assertStatus(401);
        $this->getJson('/api/stats')->assertStatus(401);
    }

    /** @test */
    public function health_endpoint_does_not_require_auth(): void
    {
        $this->getJson('/api/health')->assertStatus(200);
    }
}

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_stores_and_retrieves_typed_setting_values(): void
    {
        Setting::set('test_int', '42');
        Setting::where('key', 'test_int')->update(['type' => 'integer']);

        $val = Setting::get('test_int');
        $this->assertSame(42, $val);
    }

    /** @test */
    public function it_returns_default_when_setting_missing(): void
    {
        $val = Setting::get('nonexistent_key', 'fallback-value');
        $this->assertSame('fallback-value', $val);
    }

    /** @test */
    public function it_handles_boolean_type(): void
    {
        Setting::updateOrCreate(
            ['key' => 'bool_test'],
            ['value' => 'true', 'type' => 'boolean']
        );

        $val = Setting::get('bool_test');
        $this->assertTrue($val);
    }

    /** @test */
    public function it_handles_float_type(): void
    {
        Setting::updateOrCreate(
            ['key' => 'float_test'],
            ['value' => '3.14', 'type' => 'float']
        );

        $val = Setting::get('float_test');
        $this->assertSame(3.14, $val);
    }
}
