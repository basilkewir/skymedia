<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\StreamLog;
use App\Services\DVRService;
use Illuminate\Console\Command;

class CleanupDVR extends Command
{
    protected $signature   = 'dvr:cleanup {--channel= : Target a specific channel ID} {--log-days=30 : Prune stream logs older than N days}';
    protected $description = 'Enforce DVR rolling windows and prune old stream logs';

    public function handle(DVRService $dvr): void
    {
        $query = Channel::query();
        if ($id = $this->option('channel')) {
            $query->where('id', (int) $id);
        }

        $channels = $query->get();
        $bar = $this->output->createProgressBar($channels->count());
        $bar->start();

        foreach ($channels as $channel) {
            $dvr->enforceWindow($channel);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Prune old logs
        $days = (int) $this->option('log-days');
        $deleted = StreamLog::where('created_at', '<', now()->subDays($days))->delete();
        $this->info("DVR cleanup done. Pruned {$deleted} stream log entries older than {$days} days.");
    }
}
