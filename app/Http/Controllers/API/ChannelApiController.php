<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\StreamLog;
use App\Services\FFmpegService;
use App\Services\StreamManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'is_active', 'pid', 'playout_pid', 'push_pid', 'push_status', 'dvr_status',
            'playout_status', 'record_status',
            'last_live_at', 'last_check_at',
            'dvr_duration', 'source_type', 'push_protocol', 'retry_count',
        ])->get();

        return response()->json($channels);
    }

    public function status(Channel $channel): JsonResponse
    {
        return response()->json([
            'id'             => $channel->id,
            'name'           => $channel->name,
            'stream_status'  => $channel->stream_status,
            'playout_status' => $channel->playout_status,
            'push_status'    => $channel->push_status,
            'dvr_status'     => $channel->dvr_status,
            'record_status'  => $channel->record_status,
            'source_live'    => $channel->source_live,
            'is_active'      => $channel->is_active,
            'pid'            => $channel->pid,
            'playout_pid'    => $channel->playout_pid,
            'push_pid'       => $channel->push_pid,
            'record_pid'     => $channel->record_pid,
            'retry_count'    => $channel->retry_count,
            'last_error'     => $channel->last_error,
            'last_live_at'   => $channel->last_live_at?->toISOString(),
            'last_check_at'  => $channel->last_check_at?->toISOString(),
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

    public function bulkStart(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        $results = [];
        foreach (Channel::whereIn('id', $ids)->get() as $ch) {
            $results[$ch->id] = $this->manager->startChannel($ch);
        }
        return response()->json(['results' => $results]);
    }

    public function bulkStop(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        $results = [];
        foreach (Channel::whereIn('id', $ids)->get() as $ch) {
            $results[$ch->id] = $this->manager->stopChannel($ch);
        }
        return response()->json(['results' => $results]);
    }

    public function stats(): JsonResponse
    {
        $all = Channel::all();
        return response()->json([
            'total'        => $all->count(),
            'live'         => $all->where('stream_status', 'live')->count(),
            'fallback'     => $all->where('stream_status', 'fallback')->count(),
            'offline'      => $all->where('stream_status', 'offline')->count(),
            'error'        => $all->where('stream_status', 'error')->count(),
            'idle'         => $all->whereIn('stream_status', ['idle', 'stopped', 'starting'])->count(),
            'active'       => $all->where('is_active', true)->count(),
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
