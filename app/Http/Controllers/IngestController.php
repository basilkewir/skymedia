<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\FFmpegService;
use App\Services\IngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class IngestController extends Controller
{
    public function __construct(
        protected IngestService $ingest,
        protected FFmpegService $ffmpeg,
    ) {}

    public function start(Channel $channel): RedirectResponse
    {
        $channel->update(['is_active' => true]);
        $ok = $this->ingest->start($channel);
        return back()->with($ok ? 'success' : 'error', $ok ? 'Ingest started' : 'Failed to start ingest');
    }

    public function stop(Channel $channel): RedirectResponse
    {
        $this->ingest->stop($channel);
        return back()->with('success', 'Ingest stopped');
    }

    public function restart(Channel $channel): RedirectResponse
    {
        $this->ingest->stop($channel);
        $ok = $this->ingest->start($channel);
        return back()->with($ok ? 'success' : 'error', $ok ? 'Ingest restarted' : 'Failed to restart ingest');
    }

    public function log(Channel $channel): JsonResponse
    {
        $logFile = $this->ffmpeg->logFile($channel, 'ingest');
        return response()->json(['log' => $this->ffmpeg->readLogTail($logFile, 50)]);
    }
}
