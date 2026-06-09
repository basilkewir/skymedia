<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\DvrSegment;
use App\Services\DVRService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DvrController extends Controller
{
    public function __construct(protected DVRService $dvr) {}

    public function index(): Response
    {
        $channels = Channel::withCount('dvrSegments')
            ->withSum('dvrSegments', 'duration')
            ->withSum('dvrSegments', 'filesize')
            ->get()
            ->map(function ($c) {
                $c->dvr_hours   = round(($c->dvr_segments_sum_duration ?? 0) / 3600, 2);
                $c->dvr_mb      = round(($c->dvr_segments_sum_filesize ?? 0) / 1_048_576, 1);
                $c->dvr_pct     = $c->dvr_duration > 0
                    ? min(100, round((($c->dvr_segments_sum_duration ?? 0) / $c->dvr_duration) * 100))
                    : 0;
                return $c;
            });

        return Inertia::render('DVR/Index', ['channels' => $channels]);
    }

    public function show(Channel $channel): Response
    {
        $segments = DvrSegment::where('channel_id', $channel->id)
            ->orderBy('sequence', 'desc')
            ->paginate(100);

        $totalDuration = $this->dvr->totalDuration($channel);
        $totalSize     = $this->dvr->totalSize($channel);

        return Inertia::render('DVR/Show', [
            'channel'       => $channel,
            'segments'      => $segments,
            'totalDuration' => round($totalDuration / 3600, 2),
            'totalSize'     => round($totalSize / 1_048_576, 1),
            'maxDuration'   => $channel->dvr_duration,
        ]);
    }

    public function destroySegment(DvrSegment $segment): RedirectResponse
    {
        @unlink($segment->filepath);
        $segment->delete();

        return back()->with('success', 'Segment deleted');
    }

    public function purge(Channel $channel): RedirectResponse
    {
        $count = $this->dvr->purgeAll($channel);
        return back()->with('success', "DVR storage cleared for {$channel->name} ({$count} segments deleted)");
    }
}
