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

                    // Auto-recovery: if channel is offline and hasn't recovered
                    // within 30 seconds, refresh ingest to try next source.
                    // Pull channels: use refreshIngest() — push stays running.
                    // Push-ingest channels: restart only if listener died.
                    $ch = $channel->fresh();
                    if ($ch->stream_status === 'offline' && !$ch->source_live) {
                        $lastLive = $ch->last_live_at ? $ch->last_live_at->timestamp : 0;
                        $offlineDuration = time() - $lastLive;
                        if ($offlineDuration >= 30) {
                            $lastRestart = $this->lastAutoRestart[$ch->id] ?? 0;
                            if ((time() - $lastRestart) < 30) {
                                return; // Cooldown: don't restart more than once per 30s
                            }

                            try {
                                if ($ch->isPushIngest()) {
                                    // Managed push-ingest: only restart if the listener died.
                                    $port = (int) ($ch->ingest_port ?? 0);
                                    $portInUse = false;
                                    if ($port > 0) {
                                        $hexPort = strtoupper(dechex($port));
                                        $tcpContent = @file_get_contents('/proc/net/tcp');
                                        $portInUse = $tcpContent !== false && str_contains($tcpContent, ":{$hexPort} ");
                                        if (! $portInUse) {
                                            $tcp6Content = @file_get_contents('/proc/net/tcp6');
                                            $portInUse = $tcp6Content !== false && str_contains($tcp6Content, ":{$hexPort} ");
                                        }
                                    }
                                    if (! $portInUse) {
                                        $manager->restartChannel($ch);
                                        $this->lastAutoRestart[$ch->id] = time();
                                        $this->line(sprintf(
                                            '[%s] %-22s  AUTO-RESTART: listener restarted after %ds offline',
                                            now()->format('H:i:s'),
                                            mb_substr($ch->name, 0, 22),
                                            $offlineDuration
                                        ));
                                    }
                                } else {
                                    // Pull channel: refresh ingest without stopping push.
                                    // Tries next source in failover chain.
                                    $manager->refreshIngest($ch);
                                    $this->lastAutoRestart[$ch->id] = time();
                                    $this->line(sprintf(
                                        '[%s] %-22s  AUTO-REFRESH: ingest refreshed after %ds offline',
                                        now()->format('H:i:s'),
                                        mb_substr($ch->name, 0, 22),
                                        $offlineDuration
                                    ));
                                }
                            } catch (\Throwable $e) {
                                Log::error("Auto-recovery failed for {$ch->name}: {$e->getMessage()}");
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
