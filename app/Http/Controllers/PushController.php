<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\DVRService;
use App\Services\FFmpegService;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function __construct(
        protected PushService   $push,
        protected DVRService    $dvr,
        protected FFmpegService $ffmpeg,
    ) {}

    public function start(Request $request, Channel $channel): RedirectResponse
    {
        $mode = $request->input('mode', 'live'); // live | dvr
        $ok   = $mode === 'dvr'
            ? $this->push->startDvrPlayback($channel)
            : $this->push->startLive($channel);

        return back()->with($ok ? 'success' : 'error', $ok ? "Push ({$mode}) started" : 'Failed to start push');
    }

    public function stop(Channel $channel): RedirectResponse
    {
        $this->push->stopAll($channel);
        return back()->with('success', 'Push stopped');
    }

    public function restart(Request $request, Channel $channel): RedirectResponse
    {
        $mode = $request->input('mode', 'live');
        $this->push->stopAll($channel);
        $ok = $mode === 'dvr'
            ? $this->push->startDvrPlayback($channel)
            : $this->push->startLive($channel);

        return back()->with($ok ? 'success' : 'error', $ok ? "Push ({$mode}) restarted" : 'Failed to restart push');
    }

    public function log(Channel $channel): JsonResponse
    {
        $logFile = $this->ffmpeg->logFile($channel, 'push');
        return response()->json(['log' => $this->ffmpeg->readLogTail($logFile, 50)]);
    }
}
