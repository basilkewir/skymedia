<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\FFmpegService;
use App\Services\StreamManager;
use Illuminate\Console\Command;

class ActivateAllChannels extends Command
{
    protected $signature   = 'streams:activate-all';
    protected $description = 'Start all active channels — restarts any with dead processes';

    public function handle(StreamManager $manager, FFmpegService $ffmpeg): void
    {
        $channels = Channel::where('is_active', true)->get();

        if ($channels->isEmpty()) {
            $this->info('No active channels.');
            return;
        }

        foreach ($channels as $channel) {
            $needsStart = in_array($channel->stream_status, ['idle', 'stopped', 'error', 'offline']);

            // Also restart channels that appear live but have dead PIDs (after reboot/crash)
            if (!$needsStart && in_array($channel->stream_status, ['live', 'fallback', 'starting'])) {
                $ingestAlive = $channel->pid   && $ffmpeg->isRunning($channel->pid);
                $pushAlive   = $channel->push_pid && $ffmpeg->isRunning($channel->push_pid);

                if (!$ingestAlive && !$pushAlive) {
                    $needsStart = true;
                    $this->line("  [{$channel->name}] stuck as {$channel->stream_status} with dead PIDs — restarting");
                }
            }

            if ($needsStart) {
                $this->line("  Starting [{$channel->name}]…");
                $manager->startChannel($channel);
            } else {
                $this->line("  [{$channel->name}] already running — skipped");
            }
        }

        $this->info('Done.');
    }
}
