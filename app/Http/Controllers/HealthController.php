<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /** Health check for load balancers and external monitoring. No auth required. */
    public function check(): JsonResponse
    {
        $dbOk  = false;
        $redisOk = false;
        $ffmpegOk = false;

        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable) {}

        try {
            Redis::connection()->ping();
            $redisOk = true;
        } catch (\Throwable) {}

        $bin = config('skymedia.ffmpeg_binary', 'ffmpeg');
        $path = trim((string) shell_exec("command -v {$bin} 2>/dev/null"));
        $ffmpegOk = $path !== '';

        $healthy = $dbOk && $redisOk && $ffmpegOk;

        return response()->json([
            'status'     => $healthy ? 'ok' : 'degraded',
            'timestamp'  => now()->toISOString(),
            'checks'     => [
                'database' => $dbOk ? 'ok' : 'fail',
                'redis'    => $redisOk ? 'ok' : 'fail',
                'ffmpeg'   => $ffmpegOk ? 'ok' : 'fail',
            ],
            'system'     => [
                'php_version'      => PHP_VERSION,
                'laravel_version'  => app()->version(),
                'disk_free_bytes'  => disk_free_space(storage_path()),
                'memory_usage_mb'  => (int) (memory_get_usage(true) / 1_048_576),
            ],
        ], $healthy ? 200 : 503);
    }

    /** Lightweight liveness probe — returns 200 if the app is alive. */
    public function live(): JsonResponse
    {
        return response()->json([
            'status'    => 'alive',
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
        } catch (\Throwable) {}

        if (!$dbOk) {
            return response()->json([
                'status' => 'not ready',
                'reason' => 'database unavailable',
            ], 503);
        }

        return response()->json([
            'status'    => 'ready',
            'timestamp' => now()->toISOString(),
        ]);
    }

    /** Metrics endpoint for Prometheus / monitoring systems. */
    public function metrics(): JsonResponse
    {
        $channels  = Channel::all();
        $dvrPath   = config('skymedia.dvr_base_path', storage_path('app/dvr'));
        $diskFree  = disk_free_space($dvrPath);
        $diskTotal = disk_total_space($dvrPath);
        $diskPct   = $diskTotal > 0 ? round(($diskTotal - $diskFree) / $diskTotal * 100, 1) : 0;

        return response()->json([
            'skymedia_channels_total'     => $channels->count(),
            'skymedia_channels_active'    => $channels->where('is_active', true)->count(),
            'skymedia_channels_live'      => $channels->where('stream_status', 'live')->count(),
            'skymedia_channels_fallback'  => $channels->where('stream_status', 'fallback')->count(),
            'skymedia_channels_offline'   => $channels->where('stream_status', 'offline')->count(),
            'skymedia_channels_error'     => $channels->where('stream_status', 'error')->count(),
            'storage_free_bytes'          => $diskFree,
            'storage_total_bytes'         => $diskTotal,
            'storage_used_percent'        => $diskPct,
            'storage_warning'             => $diskPct > 85,
            'storage_critical'            => $diskPct > 95,
            'timestamp'                   => now()->toISOString(),
        ]);
    }
}
