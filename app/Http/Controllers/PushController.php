<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\FFmpegService;
use App\Services\PushService;
use App\Services\StreamManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function __construct(
        protected StreamManager $manager,
        protected PushService   $push,
        protected FFmpegService $ffmpeg,
    ) {}

    /**
     * Start push.
     * mode = 'live' → reads live.m3u8 (requires ingest to be running)
     * mode = 'dvr'  → loops concat.txt (works without ingest)
     */
    public function start(Request $request, Channel $channel): RedirectResponse
    {
        $mode = $request->input('mode', 'live');
        $ok   = $this->manager->startPush($channel, $mode);

        return back()->with(
            $ok ? 'success' : 'error',
            $ok ? "Push started ({$mode})" : ($mode === 'live'
                ? 'Push failed — make sure ingest is running and has recorded at least 2 segments'
                : 'Push failed — no DVR segments available')
        );
    }

    public function stop(Channel $channel): RedirectResponse
    {
        $this->manager->stopPush($channel);
        return back()->with('success', 'Push stopped');
    }

    public function restart(Request $request, Channel $channel): RedirectResponse
    {
        $mode = $request->input('mode', 'live');
        $this->manager->stopPush($channel);
        $ok = $this->manager->startPush($channel, $mode);

        return back()->with(
            $ok ? 'success' : 'error',
            $ok ? "Push restarted ({$mode})" : 'Push failed to restart — check log'
        );
    }

    public function log(Channel $channel): JsonResponse
    {
        return response()->json([
            'log' => $this->ffmpeg->readLogTail($this->ffmpeg->logFile($channel, 'push'), 80),
        ]);
    }
}
