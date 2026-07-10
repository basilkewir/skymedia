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
        $this->admin();
        try {
            $ok = $this->manager->startChannel($channel);
        } catch (\Throwable $e) {
            return back()->with('error', 'Ingest failed: ' . $e->getMessage());
        }
        return back()->with($ok ? 'success' : 'error', $ok ? 'Ingest started' : 'Ingest failed to start — check ffmpeg log');
    }

    public function stop(Channel $channel): RedirectResponse
    {
        $this->admin();
        $this->manager->stopChannel($channel);
        return back()->with('success', 'Ingest stopped');
    }

    public function restart(Channel $channel): RedirectResponse
    {
        $this->admin();
        try {
            if ($channel->isPushIngest()) {
                $this->manager->stopChannel($channel);
                $channel->update(['is_active' => true]);
                $ok = $this->manager->startChannel($channel->fresh());
            } else {
                $ok = $this->manager->refreshIngest($channel);
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Ingest restart failed: ' . $e->getMessage());
        }
        return back()->with($ok ? 'success' : 'error', $ok ? 'Ingest restarted' : 'Ingest failed to restart — check ffmpeg log');
    }

    public function log(Channel $channel): JsonResponse
    {
        $this->admin();
        return response()->json([
            'log' => $this->ffmpeg->readLogTail($this->ffmpeg->logFile($channel, 'ingest'), 80),
        ]);
    }

    private function admin(): void { abort_unless(auth()->user()?->is_admin, 403); }
}
