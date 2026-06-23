<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class HealthController extends Controller
{
    /** Health check for load balancers and external monitoring. No auth required. */
    public function check(): JsonResponse
    {
        $dbOk = false;
        $redisOk = false;
        $ffmpegOk = false;

        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable) {
        }

        // Redis is only considered critical if the app is actually using it.
        $usesRedis = in_array(config('cache.default'), ['redis', ' predis'], true)
            || in_array(config('queue.default'), ['redis'], true)
            || in_array(config('session.driver'), ['redis'], true);

        if ($usesRedis) {
            try {
                Redis::connection()->ping();
                $redisOk = true;
            } catch (\Throwable) {
            }
        } else {
            $redisOk = true;
        }

        $bin = config('skymedia.ffmpeg_binary', 'ffmpeg');
        $path = trim((string) shell_exec("command -v {$bin} 2>/dev/null"));
        $ffmpegOk = $path !== '';

        $healthy = $dbOk && $redisOk && $ffmpegOk;

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'timestamp' => now()->toISOString(),
            'checks' => [
                'database' => $dbOk ? 'ok' : 'fail',
                'redis' => $redisOk ? 'ok' : 'fail',
                'ffmpeg' => $ffmpegOk ? 'ok' : 'fail',
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'disk_free_bytes' => disk_free_space(storage_path()),
                'memory_usage_mb' => (int) (memory_get_usage(true) / 1_048_576),
            ],
        ], $healthy ? 200 : 503);
    }

    /** Lightweight liveness probe — returns 200 if the app is alive. */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'alive',
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Readiness probe — checks that the application can serve requests
     * and the critical upstream services are available.
     */
    public function ready(): JsonResponse
    {
        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable) {
        }

        if (! $dbOk) {
            return response()->json([
                'status' => 'not ready',
                'reason' => 'database unavailable',
            ], 503);
        }

        return response()->json([
            'status' => 'ready',
            'timestamp' => now()->toISOString(),
        ]);
    }

    /** Metrics endpoint for Prometheus / monitoring systems. */
    public function metrics(): JsonResponse
    {
        $channels = Channel::all();
        $dvrPath = config('skymedia.dvr_base_path', storage_path('app/dvr'));
        $diskFree = disk_free_space($dvrPath);
        $diskTotal = disk_total_space($dvrPath);
        $diskPct = $diskTotal > 0 ? round(($diskTotal - $diskFree) / $diskTotal * 100, 1) : 0;

        return response()->json([
            'skymedia_channels_total' => $channels->count(),
            'skymedia_channels_active' => $channels->where('is_active', true)->count(),
            'skymedia_channels_live' => $channels->where('stream_status', 'live')->count(),
            'skymedia_channels_fallback' => $channels->where('stream_status', 'fallback')->count(),
            'skymedia_channels_offline' => $channels->where('stream_status', 'offline')->count(),
            'skymedia_channels_error' => $channels->where('stream_status', 'error')->count(),
            'storage_free_bytes' => $diskFree,
            'storage_total_bytes' => $diskTotal,
            'storage_used_percent' => $diskPct,
            'storage_warning' => $diskPct > 85,
            'storage_critical' => $diskPct > 95,
            'system' => $this->systemMetrics(),
            'timestamp' => now()->toISOString(),
        ]);
    }

    /** Full system resource dashboard data. */
    public function resources(): JsonResponse
    {
        return response()->json([
            'system' => $this->systemMetrics(),
            'channels' => $this->channelProcessMetrics(),
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Read system CPU / RAM / load metrics from /proc.
     * Returns sensible defaults on non-Linux systems.
     */
    private function systemMetrics(): array
    {
        $load = sys_getloadavg() ?: [0.0, 0.0, 0.0];
        $cpuCores = max(1, (int) shell_exec('nproc 2>/dev/null'));

        $memory = [
            'total_mb' => 0,
            'free_mb' => 0,
            'used_mb' => 0,
            'used_percent' => 0.0,
        ];

        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $mTotal);
            preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $mAvail);
            preg_match('/MemFree:\s+(\d+)\s+kB/', $meminfo, $mFree);

            $totalKb = (int) ($mTotal[1] ?? 0);
            $availKb = (int) ($mAvail[1] ?? $mFree[1] ?? 0);
            $usedKb = max(0, $totalKb - $availKb);

            if ($totalKb > 0) {
                $memory = [
                    'total_mb' => (int) round($totalKb / 1024),
                    'free_mb' => (int) round($availKb / 1024),
                    'used_mb' => (int) round($usedKb / 1024),
                    'used_percent' => round($usedKb / $totalKb * 100, 1),
                ];
            }
        }

        $cpuPercent = $this->readCpuPercent();

        return [
            'cpu_percent' => $cpuPercent,
            'cpu_cores' => $cpuCores,
            'load_average_1m' => round($load[0], 2),
            'load_average_5m' => round($load[1], 2),
            'load_average_15m' => round($load[2], 2),
            'memory' => $memory,
        ];
    }

    /**
     * Compute overall CPU utilisation by sampling /proc/stat once.
     * Returns 0.0 on failure.
     */
    private function readCpuPercent(): float
    {
        if (! is_readable('/proc/stat')) {
            return 0.0;
        }

        $first = $this->parseProcStat(file_get_contents('/proc/stat'));
        usleep(250_000);
        $second = $this->parseProcStat(file_get_contents('/proc/stat'));

        $totalDiff = $second['total'] - $first['total'];
        $idleDiff = $second['idle'] - $first['idle'];

        if ($totalDiff <= 0) {
            return 0.0;
        }

        return round((1 - $idleDiff / $totalDiff) * 100, 1);
    }

    /**
     * Parse the first aggregate CPU line from /proc/stat.
     */
    private function parseProcStat(string $content): array
    {
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (! str_starts_with($line, 'cpu ')) {
                continue;
            }
            $parts = array_map('intval', array_filter(explode(' ', trim($line))));
            $total = array_sum($parts);
            $idle = ($parts[3] ?? 0) + ($parts[4] ?? 0);

            return ['total' => $total, 'idle' => $idle];
        }

        return ['total' => 0, 'idle' => 0];
    }

    /**
     * Collect CPU / memory usage for each active channel's FFmpeg processes.
     */
    private function channelProcessMetrics(): array
    {
        $result = [];

        Channel::where('is_active', true)
            ->select(['id', 'name', 'pid', 'playout_pid', 'push_pid', 'record_pid', 'stream_status', 'playout_status'])
            ->get()
            ->each(function (Channel $channel) use (&$result) {
                $pids = array_filter([
                    (int) $channel->pid,
                    (int) $channel->playout_pid,
                    (int) $channel->push_pid,
                    (int) $channel->record_pid,
                ]);

                $processes = [];
                foreach ($pids as $pid) {
                    $info = $this->processInfo($pid);
                    if ($info !== null) {
                        $processes[] = $info;
                    }
                }

                $result[] = [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'status' => $channel->stream_status,
                    'playout' => $channel->playout_status,
                    'processes' => $processes,
                ];
            });

        return $result;
    }

    /**
     * Read RSS memory and CPU time for a single PID from /proc.
     */
    private function processInfo(int $pid): ?array
    {
        $statusFile = "/proc/{$pid}/status";
        $statFile = "/proc/{$pid}/stat";

        if (! is_readable($statusFile) || ! is_readable($statFile)) {
            return null;
        }

        $status = file_get_contents($statusFile);
        $stat = file_get_contents($statFile);

        preg_match('/Name:\s+(\S+)/', $status, $nameMatch);
        preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $rssMatch);

        // stat fields: pid, comm, state, ppid, ... utime(14), stime(15)
        $statParts = explode(' ', trim($stat));
        $utime = (int) ($statParts[13] ?? 0);
        $stime = (int) ($statParts[14] ?? 0);

        $pageSize = 4096;
        $rssKb = (int) ($rssMatch[1] ?? 0);

        return [
            'pid' => $pid,
            'name' => $nameMatch[1] ?? 'unknown',
            'memory_rss_mb' => (int) round($rssKb / 1024),
            'cpu_time_seconds' => (int) round(($utime + $stime) * ($pageSize / 100) / 100),
        ];
    }
}
