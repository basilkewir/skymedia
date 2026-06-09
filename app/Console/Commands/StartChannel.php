<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\StreamManager;
use Illuminate\Console\Command;

class StartChannel extends Command
{
    protected $signature   = 'streams:start {channel : Channel ID or slug}';
    protected $description = 'Start a channel';

    public function handle(StreamManager $manager): int
    {
        $channel = Channel::where('id', $this->argument('channel'))
            ->orWhere('slug', $this->argument('channel'))
            ->first();

        if (!$channel) {
            $this->error('Channel not found');
            return self::FAILURE;
        }

        $this->info("Starting [{$channel->name}]…");
        $ok = $manager->startChannel($channel);

        if ($ok) {
            $this->info('Started — status: ' . $channel->fresh()->stream_status);
            return self::SUCCESS;
        }

        $this->error('Failed to start channel. Check logs.');
        return self::FAILURE;
    }
}
