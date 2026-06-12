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
            ->withSum('dvrSegments', 'filesize')
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
        $data = $request->validate($this->rules());

        $data['slug']       = $data['slug'] ?? Str::slug($data['name']);
        $data['dvr_path']   = config('skymedia.dvr_base_path', storage_path('app/dvr')) . '/' . $data['slug'];
        $data['is_active']  = false;
        $data['stream_status'] = 'idle';

        Channel::create($data);

        return redirect()->route('channels.index')->with('success', 'Channel created');
    }

    public function show(Channel $channel): Response
    {
        $channel->load([
            'dvrSegments'  => fn($q) => $q->orderBy('sequence', 'desc')->limit(50),
            'recordings'   => fn($q) => $q->limit(10),
        ]);
        $channel->loadCount('streamLogs');
        $channel->dvr_total_duration = $this->dvr->totalDuration($channel);
        $channel->dvr_total_size     = $this->dvr->totalSize($channel);
        $channel->dvr_buffer_pct     = $this->dvr->bufferPercent($channel);

        return Inertia::render('Channels/Show', ['channel' => $channel]);
    }

    public function edit(Channel $channel): Response
    {
        return Inertia::render('Channels/Edit', ['channel' => $channel]);
    }

    public function update(Request $request, Channel $channel): RedirectResponse
    {
        $data = $request->validate($this->rules(update: true));
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
            $channel->update(['is_active' => true]);
            $this->manager->startChannel($channel->fresh());
            $msg = "{$channel->name} started";
        }
        return back()->with('success', $msg);
    }

    public function restart(Channel $channel): RedirectResponse
    {
        $this->manager->restartChannel($channel);
        return back()->with('success', 'Channel restarted');
    }

    public function startPush(Request $request, Channel $channel): RedirectResponse
    {
        $mode = $request->input('mode', 'live'); // live | dvr | fallback
        $this->manager->startPush($channel, $mode);
        return back()->with('success', "Push started (mode: {$mode})");
    }

    public function stopPush(Channel $channel): RedirectResponse
    {
        $this->manager->stopPush($channel);
        return back()->with('success', 'Push stopped');
    }

    public function purgeDvr(Channel $channel): RedirectResponse
    {
        $n = $this->dvr->purgeAll($channel);
        return back()->with('success', "DVR cleared ({$n} segments removed)");
    }

    public function probe(Channel $channel): JsonResponse
    {
        return response()->json($this->ffmpeg->probeStream($channel));
    }

    public function diagnose(Channel $channel): JsonResponse
    {
        // Runs ffmpeg for 5 s and returns the full output — safe to call anytime
        return response()->json($this->ffmpeg->diagnoseIngest($channel));
    }

    public function logs(Channel $channel): JsonResponse
    {
        $logs = $channel->streamLogs()->latest()->limit(50)->get();
        return response()->json($logs);
    }

    // ── Validation rules ──────────────────────────────────────────────────────

    private function rules(bool $update = false): array
    {
        return [
            'name'                  => 'required|string|max:255',
            'slug'                  => $update ? 'nullable' : 'nullable|string|max:255|unique:channels,slug',
            'source_type'           => 'required|in:hls,udp,mpegts,rtmp,srt',
            'source_url'            => 'required|string|max:1000',
            'push_protocol'         => 'required|in:rtmp,srt',
            'push_url'              => 'required|string|max:500',
            'push_stream_key'       => 'required|string|max:255',
            // Video
            'push_video_codec'      => 'required|in:copy,h264,h265,vp8,vp9',
            'push_video_bitrate'    => 'nullable|integer|min:100|max:50000',
            'push_resolution'       => 'nullable|string|max:30',
            'push_framerate'        => 'nullable|integer|min:1|max:60',
            // Audio
            'push_audio_codec'      => 'required|in:copy,aac,mp3,opus,ac3',
            'push_audio_bitrate'    => 'nullable|integer|min:32|max:512',
            'push_audio_samplerate' => 'nullable|integer|in:22050,32000,44100,48000',
            'push_audio_channels'   => 'nullable|integer|in:1,2,6',
            // DVR
            'dvr_duration'          => 'required|integer|min:60|max:86400',
            'segment_duration'      => 'required|integer|min:2|max:30',
            // Recording
            'record_duration'       => 'required|integer|min:0|max:86400',
            // Behaviour
            'check_interval'        => 'required|integer|min:1|max:60',
            'max_retries'           => 'required|integer|min:0|max:20',
            'notes'                 => 'nullable|string|max:1000',
        ];
    }
}
