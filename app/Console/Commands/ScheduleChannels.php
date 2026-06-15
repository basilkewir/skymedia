<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\StreamManager;
use Illuminate\Console\Command;

class ScheduleChannels extends Command
{
    protected $signature   = 'streams:schedule';
    protected $description = 'Auto start/stop channels based on their schedule_start/schedule_stop times';

    public function handle(StreamManager $manager): void
    {
        $now  = now();
        $time = $now->format('H:i:s');
        $day  = (string) $now->dayOfWeekIso; // 1=Mon..7=Sun

        Channel::whereNotNull('schedule_start')
            ->orWhereNotNull('schedule_stop')
            ->get()
            ->each(function (Channel $channel) use ($manager, $time, $day) {
                // Check if today is an active day
                $days = $channel->schedule_days ?: '1234567';
                if (!str_contains($days, $day)) {
                    // Not a scheduled day — stop if running
                    if ($channel->is_active) {
                        $manager->stopChannel($channel);
                        $this->line("[{$channel->name}] Stopped — not a scheduled day ({$day})");
                    }
                    return;
                }

                $shouldStart = false;
                $shouldStop  = false;

                // Determine if we should be active right now
                if ($channel->schedule_start && $channel->schedule_stop) {
                    if ($channel->schedule_start <= $channel->schedule_stop) {
                        // Normal: 09:00-17:00
                        $shouldStart = $time >= $channel->schedule_start && $time < $channel->schedule_stop;
                    } else {
                        // Overnight: 22:00-06:00
                        $shouldStart = $time >= $channel->schedule_start || $time < $channel->schedule_stop;
                    }
                    $shouldStop = !$shouldStart;
                } elseif ($channel->schedule_start) {
                    $shouldStart = $time >= $channel->schedule_start;
                } elseif ($channel->schedule_stop) {
                    $shouldStop = $time >= $channel->schedule_stop;
                }

                if ($shouldStart && !$channel->is_active) {
                    $manager->startChannel($channel);
                    $this->line("[{$channel->name}] Auto-started at {$time}");
                } elseif ($shouldStop && $channel->is_active) {
                    $manager->stopChannel($channel);
                    $this->line("[{$channel->name}] Auto-stopped at {$time}");
                }
            });
    }
}
