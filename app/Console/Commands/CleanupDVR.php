<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\StreamLog;
use App\Services\DVRService;
use Illuminate\Console\Command;

class CleanupDVR extends Command
{
    protected $signature   = 'dvr:cleanup {--channel= : Target a specific channel ID} {--log-days=30 : Prune stream logs older than N days} {--orphans : Clean up orphaned DB records}';
    protected $description = 'Enforce DVR rolling windows, verify segments, and prune old stream logs';

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
            $dvr->verifySegments($channel);

            if ($this->option('orphans')) {
                $cleaned = $dvr->cleanupOrphans($channel);
                if ($cleaned > 0) {
                    $this->line(" [{$channel->name}] cleaned {$cleaned} orphan records");
                }
            }

            $dvr->enforceWindow($channel);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $days = (int) $this->option('log-days');
        $deleted = StreamLog::where('created_at', '<', now()->subDays($days))->delete();
        $this->info("DVR cleanup done. Pruned {$deleted} stream log entries older than {$days} days.");
    }
}
