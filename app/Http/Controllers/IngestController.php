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
        try {
            $ok = $this->manager->startChannel($channel);
        } catch (\Throwable $e) {
            return back()->with('error', 'Ingest failed: ' . $e->getMessage());
        }
        return back()->with($ok ? 'success' : 'error', $ok ? 'Ingest started' : 'Ingest failed to start — check ffmpeg log');
    }

    public function stop(Channel $channel): RedirectResponse
    {
        $this->manager->stopChannel($channel);
        return back()->with('success', 'Ingest stopped');
    }

    public function restart(Channel $channel): RedirectResponse
    {
        try {
            $this->manager->stopChannel($channel);
            $channel->update(['is_active' => true]);
            $ok = $this->manager->startChannel($channel->fresh());
        } catch (\Throwable $e) {
            return back()->with('error', 'Ingest restart failed: ' . $e->getMessage());
        }
        return back()->with($ok ? 'success' : 'error', $ok ? 'Ingest restarted' : 'Ingest failed to restart — check ffmpeg log');
    }

    public function log(Channel $channel): JsonResponse
    {
        return response()->json([
            'log' => $this->ffmpeg->readLogTail($this->ffmpeg->logFile($channel, 'ingest'), 80),
        ]);
    }
}
