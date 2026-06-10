<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\FFmpegService;
use App\Services\IngestService;
use App\Services\StreamManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class IngestController extends Controller
{
    public function __construct(
        protected StreamManager $manager,
        protected IngestService $ingest,
        protected FFmpegService $ffmpeg,
    ) {}

    public function start(Channel $channel): RedirectResponse
    {
        $ok = $this->manager->startChannel($channel);
        return back()->with($ok ? 'success' : 'error', $ok ? 'Channel started' : 'Failed to start channel');
    }

    public function stop(Channel $channel): RedirectResponse
    {
        $this->manager->stopChannel($channel);
        return back()->with('success', 'Channel stopped');
    }

    public function restart(Channel $channel): RedirectResponse
    {
        $this->manager->stopChannel($channel);
        $ok = $this->manager->startChannel($channel);
        return back()->with($ok ? 'success' : 'error', $ok ? 'Channel restarted' : 'Failed to restart channel');
    }

    public function log(Channel $channel): JsonResponse
    {
        $logFile = $this->ffmpeg->logFile($channel, 'ingest');
        return response()->json(['log' => $this->ffmpeg->readLogTail($logFile, 80)]);
    }
}
