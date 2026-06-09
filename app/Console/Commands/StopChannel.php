<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\StreamManager;
use Illuminate\Console\Command;

class StopChannel extends Command
{
    protected $signature   = 'streams:stop {channel : Channel ID or slug}';
    protected $description = 'Stop a channel';

    public function handle(StreamManager $manager): int
    {
        $channel = Channel::where('id', $this->argument('channel'))
            ->orWhere('slug', $this->argument('channel'))
            ->first();

        if (!$channel) {
            $this->error('Channel not found');
            return self::FAILURE;
        }

        $manager->stopChannel($channel);
        $this->info("Channel [{$channel->name}] stopped");
        return self::SUCCESS;
    }
}
