<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ChannelFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word() . ' TV';

        return [
            'name'                  => $name,
            'slug'                  => str()->slug($name) . '-' . fake()->randomNumber(3),
            'source_type'           => fake()->randomElement(['hls', 'udp', 'mpegts', 'rtmp', 'srt']),
            'source_url'            => 'https://example.com/' . fake()->slug() . '.m3u8',
            'push_protocol'         => fake()->randomElement(['rtmp', 'srt']),
            'push_url'              => 'rtmp://live.example.com/live',
            'push_stream_key'       => 'stream-key-' . fake()->randomNumber(5),
            'push_video_codec'      => 'copy',
            'push_audio_codec'      => 'aac',
            'dvr_duration'          => 3600,
            'segment_duration'      => 6,
            'dvr_enabled'           => true,
            'record_duration'       => 0,
            'check_interval'        => 5,
            'max_retries'           => 3,
            'is_active'             => false,
            'stream_status'         => 'idle',
            'playout_status'        => 'stopped',
            'push_status'           => 'stopped',
            'dvr_status'            => 'idle',
            'record_status'         => 'idle',
            'source_live'           => false,
            'retry_count'           => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active'     => true,
            'stream_status' => 'live',
            'source_live'   => true,
        ]);
    }

    public function withSource(string $type, string $url): static
    {
        return $this->state(fn(array $attributes) => [
            'source_type' => $type,
            'source_url'  => $url,
        ]);
    }
}
