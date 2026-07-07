<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestPortAllocationTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    /** @test */
    public function rtmp_ingest_port_stays_within_docker_exposed_range(): void
    {
        // Allocate all ports in the valid range
        $ports = [];
        for ($i = 0; $i < 100; $i++) {
            $channel = Channel::create([
                'name' => "Port Test {$i}",
                'slug' => "port-test-{$i}-" . fake()->randomNumber(4),
                'source_type' => 'rtmp',
                'ingest_mode' => 'push',
                'source_url' => 'push://listener',
                'rtmp_input_key' => Str::random(24),
                'push_protocol' => 'rtmp',
                'push_url' => 'rtmp://example.com/live',
                'push_stream_key' => 'key' . $i,
                'push_video_codec' => 'copy',
                'push_audio_codec' => 'aac',
                'dvr_duration' => 3600,
                'segment_duration' => 6,
                'record_duration' => 0,
                'check_interval' => 5,
                'max_retries' => 3,
            ]);

            // Simulate what ChannelController::store does for port allocation
            $port = $channel->ingest_port;
            if (! $port || Channel::where('ingest_port', $port)->where('id', '!=', $channel->id)->exists()) {
                $port = $this->allocatePort('rtmp', $channel->id);
                $channel->update(['ingest_port' => $port]);
            }

            $ports[] = $port;
        }

        // Every allocated port must be within the Docker-exposed range
        foreach ($ports as $port) {
            $this->assertGreaterThanOrEqual(20000, $port, "Port {$port} is below RTMP minimum");
            $this->assertLessThanOrEqual(20099, $port, "Port {$port} exceeds Docker-exposed RTMP range (20000-20099)");
        }
    }

    /** @test */
    public function srt_ingest_port_stays_within_docker_exposed_range(): void
    {
        $ports = [];
        for ($i = 0; $i < 5; $i++) {
            $channel = Channel::create([
                'name' => "SRT Port Test {$i}",
                'slug' => "srt-port-test-{$i}-" . fake()->randomNumber(4),
                'source_type' => 'srt',
                'ingest_mode' => 'push',
                'source_url' => 'push://listener',
                'push_protocol' => 'srt',
                'push_url' => 'srt://example.com:9000',
                'push_stream_key' => 'srtkey' . $i,
                'push_video_codec' => 'copy',
                'push_audio_codec' => 'aac',
                'dvr_duration' => 3600,
                'segment_duration' => 6,
                'record_duration' => 0,
                'check_interval' => 5,
                'max_retries' => 3,
            ]);

            $port = $this->allocatePort('srt', $channel->id);
            $channel->update(['ingest_port' => $port]);
            $ports[] = $port;
        }

        foreach ($ports as $port) {
            $this->assertGreaterThanOrEqual(30000, $port, "Port {$port} is below SRT minimum");
            $this->assertLessThanOrEqual(30099, $port, "Port {$port} exceeds Docker-exposed SRT range (30000-30099)");
        }
    }

    /**
     * Mirror the ChannelController::availableIngestPort logic.
     */
    private function allocatePort(string $protocol, int $exceptId): int
    {
        $port = $protocol === 'srt' ? 30000 : 20000;
        while (Channel::where('ingest_port', $port)->where('id', '!=', $exceptId)->exists()) {
            $port++;
        }
        $maxPort = $protocol === 'srt' ? 30099 : 20099;
        if ($port > $maxPort) {
            throw new \RuntimeException('No ingest ports are available');
        }

        return $port;
    }
}
