<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class DashboardController extends Controller
{
    /** Cache system metrics for 10 seconds to avoid repeated /proc reads under load */
    private static ?array $cachedMetrics = null;
    private static int $metricsCachedAt = 0;

    public function index(): Response|RedirectResponse
    {
        if (! auth()->user()?->is_admin) return redirect()->route('channels.index');
        $channels = Channel::withCount('dvrSegments')
            ->withSum('dvrSegments', 'duration')
            ->withSum('dvrSegments', 'filesize')
            ->get();

        $stats = [
            'total'       => $channels->count(),
            'live'        => $channels->where('stream_status', 'live')->count(),
            'fallback'    => $channels->where('stream_status', 'fallback')->count(),
            'offline'     => $channels->where('stream_status', 'offline')->count(),
            'error'       => $channels->where('stream_status', 'error')->count(),
            'idle'        => $channels->whereIn('stream_status', ['idle', 'stopped'])->count(),
            'active'      => $channels->where('is_active', true)->count(),
            'dvr_storage' => $channels->sum('dvr_segments_sum_filesize'),
        ];

        $recentLogs = StreamLog::with('channel:id,name')
            ->latest()
            ->limit(30)
            ->get();

        return Inertia::render('Dashboard', [
            'channels'   => $channels->values(),
            'stats'      => $stats,
            'recentLogs' => $recentLogs,
        ]);
    }

    /**
     * Lightweight JSON endpoint polled by the dashboard every 5s.
     * Session-authenticated (no token needed), returns channel statuses
     * plus system resource usage.
     */
    public function status(): JsonResponse
    {
        abort_unless(auth()->user()?->is_admin, 403);
        $channels = Channel::select([
            'id', 'name', 'slug', 'source_type', 'push_protocol',
            'stream_status', 'push_status', 'dvr_status', 'record_status',
            'source_live', 'is_active', 'pid', 'push_pid',
            'last_live_at', 'dvr_duration',
        ])->get();

        $dvrPath = config('skymedia.dvr_base_path', storage_path('app/dvr'));
        $diskFree = disk_free_space($dvrPath);
        $diskTotal = disk_total_space($dvrPath);

        // Count ffmpeg processes for monitoring
        exec('pgrep -c -f "ffmpeg" 2>/dev/null', $ffmpegCount, $code);
        $ffmpegProcesses = ($code === 0 && ! empty($ffmpegCount)) ? (int) trim($ffmpegCount[0]) : 0;

        return response()->json([
            'channels' => $channels,
            'system' => $this->systemMetrics(),
            'disk' => [
                'used' => $diskTotal - $diskFree,
                'total' => $diskTotal,
            ],
            'ffmpeg_processes' => $ffmpegProcesses,
        ]);
    }

    /**
     * Read system CPU / RAM / load metrics from /proc.
     * Cached for 10 seconds to avoid repeated expensive /proc reads
     * when the dashboard polls every 5s.
     */
    private function systemMetrics(): array
    {
        // Return cached metrics if fresh (< 10s old)
        if (self::$cachedMetrics !== null && (time() - self::$metricsCachedAt) < 10) {
            return self::$cachedMetrics;
        }

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

        $metrics = [
            'cpu_percent' => $this->readCpuPercent(),
            'cpu_cores' => $cpuCores,
            'load_average_1m' => round($load[0], 2),
            'load_average_5m' => round($load[1], 2),
            'load_average_15m' => round($load[2], 2),
            'memory' => $memory,
        ];

        self::$cachedMetrics = $metrics;
        self::$metricsCachedAt = time();

        return $metrics;
    }

    private function readCpuPercent(): float
    {
        if (! is_readable('/proc/stat')) {
            return 0.0;
        }

        $first = $this->parseProcStat(file_get_contents('/proc/stat'));
        usleep(100_000); // 100ms instead of 250ms to reduce CPU overhead
        $second = $this->parseProcStat(file_get_contents('/proc/stat'));

        $totalDiff = $second['total'] - $first['total'];
        $idleDiff = $second['idle'] - $first['idle'];

        if ($totalDiff <= 0) {
            return 0.0;
        }

        return round((1 - $idleDiff / $totalDiff) * 100, 1);
    }

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
}
