<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlaylistItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'title'      => fake()->sentence(3),
            'filepath'   => '/var/skymedia/media/' . fake()->uuid() . '.mp4',
            'duration'   => fake()->randomFloat(4, 10, 600),
            'sort_order' => 0,
            'is_active'  => true,
        ];
    }

    public function youtube(string $videoId = 'dQw4w9WgXcQ'): static
    {
        return $this->state(fn(array $attributes) => [
            'filepath' => "youtube:{$videoId}",
            'duration' => 200.0,
        ]);
    }

    public function local(string $path): static
    {
        return $this->state(fn(array $attributes) => [
            'filepath' => $path,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function ordered(int $order): static
    {
        return $this->state(fn(array $attributes) => [
            'sort_order' => $order,
        ]);
    }
}
