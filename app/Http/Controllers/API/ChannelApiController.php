<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\StreamLog;
use App\Services\FFmpegService;
use App\Services\StreamManager;
use Illuminate\Http\JsonResponse;

class ChannelApiController extends Controller
{
    public function __construct(
        protected StreamManager $manager,
        protected FFmpegService $ffmpeg,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(
            Channel::withCount('dvrSegments')
                ->withSum('dvrSegments', 'duration')
                ->withSum('dvrSegments', 'filesize')
                ->get()
        );
    }

    public function show(Channel $channel): JsonResponse
    {
        $channel->load(['dvrSegments' => fn($q) => $q->latest()->limit(50)]);
        return response()->json($channel);
    }

    /** Lightweight status poll — used by the Vue dashboard every N seconds */
    public function statusAll(): JsonResponse
    {
        $channels = Channel::select([
            'id', 'name', 'slug', 'stream_status', 'source_live',
            'is_active', 'pid', 'dvr_pid', 'last_live_at', 'last_check_at',
            'dvr_duration', 'source_type', 'push_protocol',
        ])->get();

        return response()->json($channels);
    }

    public function status(Channel $channel): JsonResponse
    {
        return response()->json([
            'id'            => $channel->id,
            'name'          => $channel->name,
            'stream_status' => $channel->stream_status,
            'source_live'   => $channel->source_live,
            'is_active'     => $channel->is_active,
            'pid'           => $channel->pid,
            'dvr_pid'       => $channel->dvr_pid,
            'last_live_at'  => $channel->last_live_at?->toISOString(),
            'last_check_at' => $channel->last_check_at?->toISOString(),
        ]);
    }

    public function start(Channel $channel): JsonResponse
    {
        $ok = $this->manager->startChannel($channel);
        return response()->json(['success' => $ok, 'status' => $channel->fresh()->stream_status]);
    }

    public function stop(Channel $channel): JsonResponse
    {
        $ok = $this->manager->stopChannel($channel);
        return response()->json(['success' => $ok, 'status' => 'stopped']);
    }

    public function stats(): JsonResponse
    {
        $all = Channel::all();
        return response()->json([
            'total'       => $all->count(),
            'live'        => $all->where('stream_status', 'live')->count(),
            'dvr_playback'=> $all->where('stream_status', 'dvr_playback')->count(),
            'error'       => $all->where('stream_status', 'error')->count(),
            'idle'        => $all->whereIn('stream_status', ['idle', 'stopped'])->count(),
            'active'      => $all->where('is_active', true)->count(),
        ]);
    }

    public function logs(Channel $channel): JsonResponse
    {
        $logs = StreamLog::where('channel_id', $channel->id)
            ->latest()
            ->limit(50)
            ->get();

        return response()->json($logs);
    }
}
