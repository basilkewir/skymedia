<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\ChannelSource;
use Illuminate\Console\Command;

class SeedChannelSources extends Command
{
    protected $signature = 'channels:seed-sources';
    protected $description = 'Create initial ChannelSource records from existing source_url fields for all channels';

    public function handle(): int
    {
        $channels = Channel::where('ingest_mode', 'pull')->get();
        $created = 0;

        foreach ($channels as $channel) {
            $existing = $channel->channelSources()->count();
            if ($existing > 0) {
                continue; // Already has sources
            }

            if (empty($channel->source_url)) {
                continue; // No source URL
            }

            $source = ChannelSource::create([
                'channel_id' => $channel->id,
                'source_url' => $channel->source_url,
                'source_type' => $channel->source_type,
                'priority' => 0,
                'is_active' => true,
            ]);

            $channel->update(['current_source_id' => $source->id]);
            $created++;
        }

        $this->info("Seeded {$created} channel sources.");
        return Command::SUCCESS;
    }
}
