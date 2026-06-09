<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\DVRService;
use App\Services\FFmpegService;
use App\Services\StreamManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    public function __construct(
        protected FFmpegService $ffmpeg,
        protected StreamManager $manager,
        protected DVRService    $dvr,
    ) {}

    public function index(): Response
    {
        $channels = Channel::withCount('dvrSegments')
            ->withCount('streamLogs')
            ->withSum('dvrSegments', 'duration')
            ->latest()
            ->paginate(20);

        return Inertia::render('Channels/Index', ['channels' => $channels]);
    }

    public function create(): Response
    {
        return Inertia::render('Channels/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'slug'             => 'nullable|string|max:255|unique:channels,slug',
            'source_type'      => 'required|in:hls,udp,mpegts,rtmp,srt',
            'source_url'       => 'required|string|max:1000',
            'push_protocol'    => 'required|in:rtmp,srt',
            'push_url'         => 'required|string|max:500',
            'push_stream_key'  => 'required|string|max:255',
            'dvr_duration'     => 'required|integer|min:60|max:86400',
            'segment_duration' => 'required|integer|min:2|max:30',
            'check_interval'   => 'required|integer|min:1|max:60',
            'max_retries'      => 'required|integer|min:0|max:10',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $data['slug']          = $data['slug'] ?? Str::slug($data['name']);
        $data['dvr_path']      = config('skymedia.dvr_base_path', storage_path('app/dvr')) . '/' . $data['slug'];
        $data['is_active']     = false;
        $data['stream_status'] = 'idle';
        $data['push_status']   = 'idle';
        $data['dvr_status']    = 'idle';

        Channel::create($data);

        return redirect()->route('channels.index')->with('success', 'Channel created');
    }

    public function show(Channel $channel): Response
    {
        $channel->load(['dvrSegments' => fn($q) => $q->orderBy('sequence', 'desc')->limit(100)]);
        $channel->loadCount('streamLogs');
        $channel->dvr_total_duration = $this->dvr->totalDuration($channel);

        return Inertia::render('Channels/Show', ['channel' => $channel]);
    }

    public function edit(Channel $channel): Response
    {
        return Inertia::render('Channels/Edit', ['channel' => $channel]);
    }

    public function update(Request $request, Channel $channel): RedirectResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'source_type'      => 'required|in:hls,udp,mpegts,rtmp,srt',
            'source_url'       => 'required|string|max:1000',
            'push_protocol'    => 'required|in:rtmp,srt',
            'push_url'         => 'required|string|max:500',
            'push_stream_key'  => 'required|string|max:255',
            'dvr_duration'     => 'required|integer|min:60|max:86400',
            'segment_duration' => 'required|integer|min:2|max:30',
            'check_interval'   => 'required|integer|min:1|max:60',
            'max_retries'      => 'required|integer|min:0|max:10',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $channel->update($data);

        return redirect()->route('channels.show', $channel)->with('success', 'Channel updated');
    }

    public function destroy(Channel $channel): RedirectResponse
    {
        $this->manager->stopChannel($channel);
        $channel->delete();

        return redirect()->route('channels.index')->with('success', 'Channel deleted');
    }

    public function toggle(Channel $channel): RedirectResponse
    {
        if ($channel->is_active) {
            $this->manager->stopChannel($channel);
            $msg = "{$channel->name} stopped";
        } else {
            $this->manager->startChannel($channel);
            $msg = "{$channel->name} started";
        }

        return back()->with('success', $msg);
    }

    public function restart(Channel $channel): RedirectResponse
    {
        $this->manager->stopChannel($channel);
        $channel->update(['is_active' => true]);
        $this->manager->startChannel($channel);

        return back()->with('success', 'Channel restarted');
    }

    public function purgeDvr(Channel $channel): RedirectResponse
    {
        $this->dvr->purgeAll($channel);
        return back()->with('success', 'DVR data cleared');
    }

    public function probe(Channel $channel): JsonResponse
    {
        return response()->json($this->ffmpeg->probeStream($channel));
    }
}
