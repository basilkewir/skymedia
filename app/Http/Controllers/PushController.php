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
        $mode = $request->input('mode', 'live');

        $ok = $mode === 'dvr'
            ? $this->push->startDvrPlayback($channel)
            : $this->push->start($channel, waitForHls: false);

        return back()->with($ok ? 'success' : 'error', $ok ? "Push started ({$mode})" : 'Failed to start push');
    }

    public function stop(Channel $channel): RedirectResponse
    {
        $this->push->stop($channel);
        return back()->with('success', 'Push stopped');
    }

    public function restart(Request $request, Channel $channel): RedirectResponse
    {
        $mode = $request->input('mode', 'live');
        $this->push->stop($channel);

        $ok = $mode === 'dvr'
            ? $this->push->startDvrPlayback($channel)
            : $this->push->start($channel, waitForHls: false);

        return back()->with($ok ? 'success' : 'error', $ok ? "Push restarted ({$mode})" : 'Failed to restart push');
    }

    public function log(Channel $channel): JsonResponse
    {
        $logFile = $this->ffmpeg->logFile($channel, 'push');
        return response()->json(['log' => $this->ffmpeg->readLogTail($logFile, 80)]);
    }
}
