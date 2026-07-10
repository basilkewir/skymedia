<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Setting;
use App\Services\StreamManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorStreams extends Command
{
    protected $signature   = 'streams:monitor {--channel= : Limit to one channel ID}';
    protected $description = 'Long-running stream monitor daemon — never leave the output offline';

    private array $lastChecked = [];
    private array $lastAutoRestart = [];

    public function handle(StreamManager $manager): void
    {
        $this->info('SkyMedia Monitor started — PID ' . getmypid());
        $tick = max(1, (int) (Setting::get('monitor_tick') ?? config('skymedia.monitor_tick', 3)));

        while (true) {
            try {
                $query = Channel::where('is_active', true);
                if ($id = $this->option('channel')) {
                    $query->where('id', (int) $id);
                }

                $query->each(function (Channel $channel) use ($manager) {
                    $interval  = max(1, (int) $channel->check_interval);
                    $lastCheck = $this->lastChecked[$channel->id] ?? 0;

                    if ((time() - $lastCheck) >= $interval) {
                        $manager->monitorChannel($channel->fresh());
                        $this->lastChecked[$channel->id] = time();

                        $ch = $channel->fresh();
                        $this->line(sprintf(
                            '[%s] %-22s  ingest=%-10s  push=%-10s  rec=%-10s  src=%s',
                            now()->format('H:i:s'),
                            mb_substr($ch->name, 0, 22),
                            $ch->stream_status,
                            $ch->push_status,
                            $ch->record_status,
                            $ch->source_live ? 'LIVE' : 'DOWN'
                        ));
                    }

                    // Auto-recovery: if channel is offline, refresh ingest to try
                    // next source. Cooldown is 15s to avoid hammering dead sources
                    // while still recovering quickly when a source comes back.
                    $ch = $channel->fresh();
                    if ($ch->stream_status === 'offline' && ! $ch->source_live) {
                        $lastRestart = $this->lastAutoRestart[$ch->id] ?? 0;
                        // Cooldown: 15s for pull channels, 20s for push-ingest
                        $cooldown = $ch->isPushIngest() ? 20 : 15;
                        if ((time() - $lastRestart) < $cooldown) {
                            return;
                        }

                        try {
                            if ($ch->isPushIngest()) {
                                // The loop wrapper keeps the listener alive automatically.
                                // Only restart if the loop process itself has died.
                                if (! $manager->isListenerLoopRunning($ch)) {
                                    $manager->restartChannel($ch);
                                    $this->lastAutoRestart[$ch->id] = time();
                                    $this->line(sprintf(
                                        '[%s] %-22s  AUTO-RESTART: listener loop restarted',
                                        now()->format('H:i:s'),
                                        mb_substr($ch->name, 0, 22)
                                    ));
                                }
                            } else {
                                // Pull channel: refresh ingest without stopping push.
                                $manager->refreshIngest($ch);
                                $this->lastAutoRestart[$ch->id] = time();
                                $this->line(sprintf(
                                    '[%s] %-22s  AUTO-REFRESH: trying next source',
                                    now()->format('H:i:s'),
                                    mb_substr($ch->name, 0, 22)
                                ));
                            }
                        } catch (\Throwable $e) {
                            Log::error("Auto-recovery failed for {$ch->name}: {$e->getMessage()}");
                        }
                    }
                });

            } catch (\Throwable $e) {
                Log::error('Monitor loop error: ' . $e->getMessage());
                $this->error($e->getMessage());
            }

            sleep($tick);
        }
    }
}
