<?php

namespace App\Console\Commands;

use App\Services\StreamManager;
use Illuminate\Console\Command;

class ActivateAllChannels extends Command
{
    protected $signature   = 'streams:activate-all';
    protected $description = 'Start all active channels that are not already running';

    public function handle(StreamManager $manager): void
    {
        $manager->activateAll();
        $this->info('All active channels started');
    }
}
