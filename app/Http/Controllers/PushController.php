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

    public function start(Request $request, Channel $channel): RedirectResponse
    {
        $mode = $request->input('mode', 'live');

        if (empty($channel->push_url)) {
            return back()->with('error', 'Push URL is not configured — edit the channel and set a push URL and stream key');
        }

        $ok = $mode === 'live'
            ? $this->manager->startPush($channel)
            : $this->push->startDvrPlayback($channel);

        return back()->with(
            $ok ? 'success' : 'error',
            $ok ? "Push started ({$mode})" : ($mode === 'live'
                ? 'Push failed — playlist not ready. Wait for ingest to record at least 2 segments, then try again. View Push Log for details.'
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
        $ok = $mode === 'live'
            ? $this->manager->startPush($channel)
            : $this->push->startDvrPlayback($channel);

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
