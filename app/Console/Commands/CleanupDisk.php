<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Recording;
use App\Models\StreamLog;
use App\Services\DVRService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupDisk extends Command
{
    protected $signature = 'disk:cleanup
                            {--target=95 : Target max disk usage percentage}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Enforce max disk usage by cleaning up DVR segments, recordings, and logs';

    private int $freedBytes = 0;

    private int $targetPct;

    private bool $dryRun;

    public function handle(DVRService $dvr): int
    {
        $this->targetPct = (int) $this->option('target');
        $this->dryRun = $this->option('dry-run');
        $this->freedBytes = 0;

        $dvrPath = config('skymedia.dvr_base_path', storage_path('app/dvr'));
        $usage = $this->getDiskUsage($dvrPath);

        $this->info("Disk usage: {$usage['pct']}% ({$this->formatBytes($usage['used'])} / {$this->formatBytes($usage['total'])})");
        $this->info("Free: {$this->formatBytes($usage['free'])} | Target: max {$this->targetPct}%");

        if ($usage['pct'] <= $this->targetPct) {
            $this->info("Disk usage is within limits. No cleanup needed.");
            return self::SUCCESS;
        }

        $this->warn("Disk usage {$usage['pct']}% exceeds target {$this->targetPct}% — starting cleanup...");

        // Calculate how much we need to free to hit the target
        $bytesToFree = (int) (($usage['pct'] - $this->targetPct) / 100 * $usage['total']);
        $this->info("Need to free at least: {$this->formatBytes($bytesToFree)}");

        // Phase 1: Clean old logs (safe, immediate)
        $this->cleanLogs();

        // Check if we're under target now
        $usage = $this->getDiskUsage($dvrPath);
        if ($usage['pct'] <= $this->targetPct) {
            $this->info("Cleanup complete after phase 1 (logs). Usage: {$usage['pct']}%");
            return self::SUCCESS;
        }

        // Phase 2: Aggressively trim DVR segments from all channels
        $this->cleanDvrSegments($dvr);

        $usage = $this->getDiskUsage($dvrPath);
        if ($usage['pct'] <= $this->targetPct) {
            $this->info("Cleanup complete after phase 2 (DVR segments). Usage: {$usage['pct']}%");
            return self::SUCCESS;
        }

        // Phase 3: Prune old recordings (keep only latest per channel)
        $this->cleanRecordings();

        $usage = $this->getDiskUsage($dvrPath);
        if ($usage['pct'] <= $this->targetPct) {
            $this->info("Cleanup complete after phase 3 (recordings). Usage: {$usage['pct']}%");
            return self::SUCCESS;
        }

        // Phase 4: Nuclear option — purge all DVR segments from offline channels
        $this->cleanOfflineChannelsDvr($dvr);

        $usage = $this->getDiskUsage($dvrPath);
        $this->info("Final disk usage: {$usage['pct']}%");
        $this->info("Total freed: {$this->formatBytes($this->freedBytes)}");

        if ($usage['pct'] > $this->targetPct) {
            $this->error("WARNING: Could not reach target {$this->targetPct}%. Disk is critically full.");
            $this->error("Consider reducing DVR durations or adding more storage.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function getDiskUsage(string $path): array
    {
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        $pct = $total > 0 ? round($used / $total * 100, 1) : 0;

        return compact('total', 'free', 'used', 'pct');
    }

    private function cleanLogs(): void
    {
        $this->line("Phase 1: Cleaning old logs...");

        // Stream logs older than 7 days
        $deleted = StreamLog::where('created_at', '<', now()->subDays(7))->delete();
        if ($deleted > 0) {
            $this->line("  Deleted {$deleted} stream log entries (>7 days)");
        }

        // Application log files older than 3 days
        $logDirs = [
            storage_path('logs'),
            config('skymedia.log_base_path', storage_path('logs/streams')),
        ];

        foreach ($logDirs as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob("{$dir}/*.log") ?: [] as $file) {
                if (filemtime($file) < time() - 3 * 86400) {
                    $size = filesize($file);
                    if (!$this->dryRun) {
                        @unlink($file);
                    }
                    $this->freedBytes += $size;
                    $this->line("  Deleted log: " . basename($file) . " ({$this->formatBytes($size)})");
                }
            }
            // Also clean rotated logs
            foreach (glob("{$dir}/*.log.*") ?: [] as $file) {
                if (filemtime($file) < time() - 1 * 86400) {
                    $size = filesize($file);
                    if (!$this->dryRun) {
                        @unlink($file);
                    }
                    $this->freedBytes += $size;
                }
            }
        }

        // Clean DVR concat and temp files
        $dvrBase = config('skymedia.dvr_base_path', storage_path('app/dvr'));
        foreach (glob("{$dvrBase}/*/concat.txt") ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob("{$dvrBase}/*/playout_concat_*.txt") ?: [] as $f) {
            @unlink($f);
        }
    }

    private function cleanDvrSegments(DVRService $dvr): void
    {
        $this->line("Phase 2: Aggressively trimming DVR segments...");

        // First, enforce normal DVR windows (time + count based)
        $channels = Channel::all();
        foreach ($channels as $channel) {
            $dvr->enforceWindow($channel);
        }
        $this->line("  Enforced DVR windows for " . $channels->count() . " channels");

        // If still over target, aggressively reduce DVR durations temporarily
        $dvrPath = config('skymedia.dvr_base_path', storage_path('app/dvr'));
        $usage = $this->getDiskUsage($dvrPath);

        if ($usage['pct'] > $this->targetPct) {
            $this->warn("  Still over target — aggressively purging oldest DVR segments...");

            // Delete the oldest 30% of segments from each channel
            foreach ($channels as $channel) {
                $segCount = \App\Models\DvrSegment::where('channel_id', $channel->id)->count();
                $toDelete = (int) ceil($segCount * 0.3);
                if ($toDelete <= 0) continue;

                $oldest = \App\Models\DvrSegment::where('channel_id', $channel->id)
                    ->orderBy('sequence')
                    ->limit($toDelete)
                    ->get();

                foreach ($oldest as $seg) {
                    $size = $seg->filesize ?? 0;
                    if (!$this->dryRun) {
                        @unlink($seg->filepath);
                        $seg->delete();
                        $channel->decrement('storage_used_bytes', $size);
                    }
                    $this->freedBytes += $size;
                }

                if ($toDelete > 0) {
                    $this->line("  Purged {$toDelete} oldest segments from {$channel->name}");
                }
            }

            // Also delete orphan .ts files not tracked in DB
            foreach ($channels as $channel) {
                $tracked = \App\Models\DvrSegment::where('channel_id', $channel->id)
                    ->pluck('filepath')
                    ->toArray();
                foreach (glob($channel->dvr_directory . '/seg_*.ts') ?: [] as $f) {
                    if (!in_array($f, $tracked, true)) {
                        $size = filesize($f);
                        if (!$this->dryRun) {
                            @unlink($f);
                        }
                        $this->freedBytes += $size;
                    }
                }
            }
        }
    }

    private function cleanRecordings(): void
    {
        $this->line("Phase 3: Pruning old recordings...");

        $channels = Channel::all();
        foreach ($channels as $channel) {
            // Keep only 1 most recent recording per channel
            $old = Recording::where('channel_id', $channel->id)
                ->where('status', 'completed')
                ->whereNot('filepath', $channel->fallback_recording_path)
                ->orderByDesc('completed_at')
                ->skip(1)
                ->get();

            foreach ($old as $rec) {
                $size = filesize($rec->filepath) ?? 0;
                if (!$this->dryRun) {
                    @unlink($rec->filepath);
                    $rec->delete();
                    $channel->decrement('storage_used_bytes', $size);
                }
                $this->freedBytes += $size;
                $this->line("  Deleted recording from {$channel->name}: " . basename($rec->filepath) . " ({$this->formatBytes($size)})");
            }

            // Also delete failed/stale recordings
            Recording::where('channel_id', $channel->id)
                ->whereIn('status', ['failed'])
                ->each(function ($rec) use ($channel) {
                    $size = filesize($rec->filepath) ?? 0;
                    if (!$this->dryRun) {
                        @unlink($rec->filepath);
                        $rec->delete();
                        $channel->decrement('storage_used_bytes', $size);
                    }
                    $this->freedBytes += $size;
                });

            // Delete orphaned rec_*.mp4 files not in DB
            $tracked = Recording::where('channel_id', $channel->id)
                ->pluck('filepath')
                ->toArray();
            foreach (glob($channel->dvr_directory . '/rec_*.mp4') ?: [] as $f) {
                if (!in_array($f, $tracked, true)) {
                    $size = filesize($f);
                    if (!$this->dryRun) {
                        @unlink($f);
                    }
                    $this->freedBytes += $size;
                    $this->line("  Deleted orphan recording: " . basename($f) . " ({$this->formatBytes($size)})");
                }
            }

            // Delete fallback loop assets (can be regenerated)
            foreach (glob($channel->dvr_directory . '/fallback_loop*.mp4') ?: [] as $f) {
                $size = filesize($f);
                if ($size > 0) {
                    if (!$this->dryRun) {
                        @unlink($f);
                    }
                    $this->freedBytes += $size;
                }
            }
        }
    }

    private function cleanOfflineChannelsDvr(DVRService $dvr): void
    {
        $this->line("Phase 4: Nuclear — purging DVR from offline channels...");

        // Find channels that are offline and purge their DVR segments
        $offlineChannels = Channel::whereIn('stream_status', ['offline', 'stopped', 'error', 'idle'])
            ->get();

        foreach ($offlineChannels as $channel) {
            $deleted = $dvr->purgeAll($channel);
            if ($deleted > 0) {
                $this->line("  Purged {$deleted} segments from offline channel: {$channel->name}");
            }
        }

        // If still over target, purge DVR from ALL channels (aggressive)
        $dvrPath = config('skymedia.dvr_base_path', storage_path('app/dvr'));
        $usage = $this->getDiskUsage($dvrPath);

        if ($usage['pct'] > $this->targetPct) {
            $this->error("  Still over target — purging DVR from ALL channels...");

            $allChannels = Channel::all();
            foreach ($allChannels as $channel) {
                $deleted = $dvr->purgeAll($channel);
                if ($deleted > 0) {
                    $this->line("  Purged {$deleted} segments from {$channel->name}");
                }
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.2f %s', $bytes, $units[$i]);
    }
}
