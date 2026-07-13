<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Setting;
use App\Services\StreamManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorStreams extends Command
{
    protected $signature = 'streams:monitor {--channel= : Limit to one channel ID}';

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
                    $interval = max(1, (int) $channel->check_interval);
                    $lastCheck = $this->lastChecked[$channel->id] ?? 0;

                    if ((time() - $lastCheck) >= $interval) {
                        try {
                            $manager->monitorChannel($channel->fresh());
                        } catch (\Throwable $e) {
                            Log::error("Monitor failed for {$channel->name}: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
                        }
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
                    // next source. The cooldown is scaled by retry_count so a
                    // repeatedly-failing upstream is given progressively more
                    // breathing room (back-pressure), and a per-channel jitter
                    // prevents dozens of dead channels from retrying on the
                    // exact same tick — which previously serialised through
                    // refreshIngest's wait loop and starved the whole monitor.
                    $ch = $channel->fresh();
                    if (($ch->stream_status === 'offline' || $ch->stream_status === 'starting')
                        && ! $ch->source_live
                        && ! $manager->isIngestRunning($ch)) {
                        $lastRestart = $this->lastAutoRestart[$ch->id] ?? 0;
                        // Base: 15s (pull) / 20s (push), + deterministic jitter
                        // based on channel id (0-4s) so retries spread out,
                        // + up to 60s back-pressure scaled by retry_count.
                        $base = $ch->isPushIngest() ? 12 : 8;
                        $jitter = $ch->id % 5;
                        $backoff = min(45, ((int) $ch->retry_count) * 3);
                        $cooldown = $base + $jitter + $backoff;
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
                                    '[%s] %-22s  AUTO-REFRESH: trying next source (cd=%ds)',
                                    now()->format('H:i:s'),
                                    mb_substr($ch->name, 0, 22),
                                    $cooldown
                                ));
                            }
                        } catch (\Throwable $e) {
                            Log::error("Auto-recovery failed for {$ch->name}: {$e->getMessage()}");
                        }
                    }
                });

            } catch (\Throwable $e) {
                Log::error('Monitor loop error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->error($e->getMessage());
            }

            sleep($tick);
        }
    }
}
