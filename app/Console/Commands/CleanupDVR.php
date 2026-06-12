<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Recording;
use App\Models\StreamLog;
use App\Services\DVRService;
use Illuminate\Console\Command;

class CleanupDVR extends Command
{
    protected $signature   = 'dvr:cleanup
                                {--channel= : Target a specific channel ID}
                                {--log-days=30 : Prune stream logs older than N days}
                                {--keep-recordings=3 : Completed recordings to keep per channel}';
    protected $description = 'Enforce DVR rolling windows, prune old recordings and stream logs';

    public function handle(DVRService $dvr): void
    {
        $query = Channel::query();
        if ($id = $this->option('channel')) {
            $query->where('id', (int) $id);
        }

        $channels = $query->get();
        $bar = $this->output->createProgressBar($channels->count());
        $bar->start();

        $keepRec = (int) $this->option('keep-recordings');

        foreach ($channels as $channel) {
            // DVR rolling window
            $dvr->enforceWindow($channel);

            // Prune old completed recordings beyond keep limit
            $old = Recording::where('channel_id', $channel->id)
                ->where('status', 'completed')
                ->whereNot('filepath', $channel->fallback_recording_path)
                ->orderByDesc('completed_at')
                ->skip($keepRec)
                ->take(1000)
                ->get();

            foreach ($old as $rec) {
                @unlink($rec->filepath);
                $rec->delete();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Prune stream logs
        $days    = (int) $this->option('log-days');
        $deleted = StreamLog::where('created_at', '<', now()->subDays($days))->delete();
        $this->info("Done. Pruned {$deleted} log entries older than {$days} days.");
    }
}
