<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\StreamManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorStreams extends Command
{
    protected $signature   = 'streams:monitor {--channel= : Limit to one channel ID}';
    protected $description = 'Long-running stream monitor daemon';

    private array $lastChecked = [];

    public function handle(StreamManager $manager): void
    {
        $this->info('SkyMedia Monitor started — PID ' . getmypid());
        $tick = (int) config('skymedia.monitor_tick', 3);

        while (true) {
            try {
                $query = Channel::where('is_active', true);

                if ($id = $this->option('channel')) {
                    $query->where('id', (int) $id);
                }

                $query->each(function (Channel $channel) use ($manager) {
                    $interval  = max(1, $channel->check_interval);
                    $lastCheck = $this->lastChecked[$channel->id] ?? 0;

                    if ((time() - $lastCheck) >= $interval) {
                        $manager->monitorChannel($channel->fresh());
                        $this->lastChecked[$channel->id] = time();
                        $ch = $channel->fresh();
                        $this->line(sprintf(
                            '[%s] %-20s  src=%-4s  dvr=%-10s  push=%-10s  stream=%-12s  retry=%d',
                            now()->format('H:i:s'),
                            $ch->name,
                            $ch->source_live ? 'LIVE' : 'DOWN',
                            $ch->dvr_status,
                            $ch->push_status,
                            $ch->stream_status,
                            $ch->retry_count
                        ));
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
