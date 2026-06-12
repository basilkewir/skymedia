<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $channels = Channel::withCount('dvrSegments')
            ->withSum('dvrSegments', 'duration')
            ->withSum('dvrSegments', 'filesize')
            ->get();

        $stats = [
            'total'       => $channels->count(),
            'live'        => $channels->where('stream_status', 'live')->count(),
            'dvr'         => $channels->whereIn('stream_status', ['dvr_playback', 'fallback'])->count(),
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
     * Session-authenticated (no token needed), returns channel statuses.
     */
    public function status(): JsonResponse
    {
        $channels = Channel::select([
            'id', 'name', 'slug', 'source_type', 'push_protocol',
            'stream_status', 'push_status', 'dvr_status', 'record_status',
            'source_live', 'is_active', 'pid', 'push_pid',
            'last_live_at', 'dvr_duration',
        ])->get();

        return response()->json(['channels' => $channels]);
    }
}
