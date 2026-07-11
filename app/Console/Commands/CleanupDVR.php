<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Recording;
use App\Models\StreamLog;
use App\Services\DVRService;
use Illuminate\Console\Command;

class CleanupDVR extends Command
{
    protected $signature = 'dvr:cleanup
                                {--channel= : Target a specific channel ID}
                                {--log-days=30 : Prune stream logs older than N days}
                                {--keep-recordings= : Override per-channel recording retention count}';

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

        $globalKeep = $this->option('keep-recordings');

        foreach ($channels as $channel) {
            // DVR rolling window
            $dvr->enforceWindow($channel);

            // Per-channel retention policy or global override
            $keepRec = $globalKeep !== null
                ? (int) $globalKeep
                : max(1, (int) ($channel->keep_recordings ?? 3));

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

            // Always delete failed recordings immediately
            Recording::where('channel_id', $channel->id)
                ->where('status', 'failed')
                ->each(function (Recording $rec) {
                    @unlink($rec->filepath);
                    $rec->delete();
                });

            // Clean up orphaned .mp4 files on disk not tracked in DB
            $tracked = Recording::where('channel_id', $channel->id)
                ->pluck('filepath')->toArray();
            foreach (glob($channel->dvr_directory . '/rec_*.mp4') ?: [] as $f) {
                if (! in_array($f, $tracked, true)) {
                    // Only delete orphaned files older than 1 hour (safety window)
                    if (filemtime($f) < (time() - 3600)) {
                        @unlink($f);
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Prune stream logs
        $days = (int) $this->option('log-days');
        $deleted = StreamLog::where('created_at', '<', now()->subDays($days))->delete();
        $this->info("Done. Pruned {$deleted} log entries older than {$days} days.");

        // Disk space check — warn if DVR storage is getting full
        $dvrPath = config('skymedia.dvr_base_path', storage_path('app/dvr'));
        $diskFree = disk_free_space($dvrPath);
        $diskTotal = disk_total_space($dvrPath);
        $pct = $diskTotal > 0 ? round(($diskTotal - $diskFree) / $diskTotal * 100, 1) : 0;

        if ($pct > 95) {
            $this->warn("CRITICAL: DVR storage is {$pct}% full — " . round($diskFree / 1_073_741_824, 1) . ' GB remaining!');
        } elseif ($pct > 85) {
            $this->warn("WARNING: DVR storage is {$pct}% full — " . round($diskFree / 1_073_741_824, 1) . ' GB remaining');
        } else {
            $this->info("DVR storage: {$pct}% used — " . round($diskFree / 1_073_741_824, 1) . ' GB free');
        }
    }
}
