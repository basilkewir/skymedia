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

                    // Auto-recovery: if channel is offline and hasn't recovered
                    // within 30 seconds, restart the ingest to accept reconnections.
                    $ch = $channel->fresh();
                    if ($ch->stream_status === 'offline' && !$ch->source_live) {
                        $lastLive = $ch->last_live_at ? $ch->last_live_at->timestamp : 0;
                        $offlineDuration = time() - $lastLive;
                        if ($offlineDuration >= 30) {
                            try {
                                if ($ch->isPushIngest()) {
                                    // Managed channel: only restart if the listener died.
                                    // If it's alive and listening, leave it alone —
                                    // killing it interrupts vMix/encoder connections.
                                    $port = (int) ($ch->ingest_port ?? 0);
                                    $ssOut = $port > 0 ? shell_exec("ss -tlnp sport = :{$port} 2>/dev/null") : '';
                                    $portInUse = $ssOut && str_contains($ssOut, 'pid=');
                                    if (! $portInUse) {
                                        $manager->restartChannel($ch);
                                        $this->line(sprintf(
                                            '[%s] %-22s  AUTO-RESTART: listener restarted after %ds offline',
                                            now()->format('H:i:s'),
                                            mb_substr($ch->name, 0, 22),
                                            $offlineDuration
                                        ));
                                    }
                                } else {
                                    // Pull channel: restart ingest to retry source
                                    $manager->restartChannel($ch);
                                    $this->line(sprintf(
                                        '[%s] %-22s  AUTO-RESTART: ingest restarted after %ds offline',
                                        now()->format('H:i:s'),
                                        mb_substr($ch->name, 0, 22),
                                        $offlineDuration
                                    ));
                                }
                            } catch (\Throwable $e) {
                                Log::error("Auto-restart failed for {$ch->name}: {$e->getMessage()}");
                            }
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
