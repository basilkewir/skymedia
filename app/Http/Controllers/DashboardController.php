<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\DvrSegment;
use App\Models\StreamLog;
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
            'dvr'         => $channels->where('stream_status', 'dvr_playback')->count(),
            'error'       => $channels->where('stream_status', 'error')->count(),
            'idle'        => $channels->whereIn('stream_status', ['idle', 'stopped'])->count(),
            'active'      => $channels->where('is_active', true)->count(),
            'dvr_storage' => $channels->sum('dvr_segments_sum_filesize'),  // bytes
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
}
