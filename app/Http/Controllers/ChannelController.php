<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\DVRService;
use App\Services\FFmpegService;
use App\Services\IngestService;
use App\Services\PushService;
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
        protected IngestService $ingest,
        protected PushService   $push,
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
        $data = $request->validate($this->rules());

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
        $channel->loadCount(['dvrSegments', 'streamLogs']);
        $channel->load(['dvrSegments' => fn($q) => $q->orderBy('sequence', 'desc')->limit(50)]);
        $channel->dvr_total_duration = $this->dvr->totalDuration($channel);
        $channel->dvr_total_size     = $this->dvr->totalSize($channel);
        $channel->dvr_buffer_pct     = $this->dvr->bufferPercent($channel);
        $channel->ingest_running     = $this->ingest->isRunning($channel);
        $channel->push_running       = $this->push->isRunning($channel);

        $recentLogs = $channel->streamLogs()->latest()->limit(20)->get();

        return Inertia::render('Channels/Show', [
            'channel'    => $channel,
            'recentLogs' => $recentLogs,
        ]);
    }

    public function toggle(Channel $channel): RedirectResponse
    {
        if ($channel->is_active) {
            $this->manager->stopChannel($channel);
        } else {
            $this->manager->startChannel($channel);
        }
        return back();
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
        $this->ingest->stop($channel);
        $this->push->stop($channel);
        $channel->delete();

        return redirect()->route('channels.index')->with('success', 'Channel deleted');
    }

    public function probe(Channel $channel): JsonResponse
    {
        return response()->json($this->ffmpeg->probeStream($channel));
    }

    // ===================================================================
    //  VALIDATION RULES
    // ===================================================================

    private function rules(bool $update = false): array
    {
        return [
            // Basic
            'name'             => 'required|string|max:255',
            'slug'             => $update ? 'nullable' : 'nullable|string|max:255|unique:channels,slug',
            'notes'            => 'nullable|string|max:1000',

            // Source
            'source_type'      => 'required|in:hls,udp,mpegts,rtmp,srt',
            'source_url'       => 'required|string|max:1000',

            // Push destination
            'push_protocol'    => 'required|in:rtmp,srt',
            'push_url'         => 'required|string|max:500',
            'push_stream_key'  => 'nullable|string|max:255',

            // Push video encoding
            'push_video_codec'   => 'required|in:copy,h264,h265,vp8,vp9',
            'push_video_bitrate' => 'nullable|integer|min:100|max:50000',
            'push_resolution'    => ['nullable', 'string', 'max:20', 'regex:/^\d+x\d+$/'],
            'push_framerate'     => 'nullable|integer|min:1|max:120',

            // Push audio encoding
            'push_audio_codec'       => 'required|in:copy,aac,mp3,opus,ac3',
            'push_audio_bitrate'     => 'required|integer|min:32|max:512',
            'push_audio_samplerate'  => 'required|in:22050,44100,48000,96000',
            'push_audio_channels'    => 'required|in:1,2,6',

            // DVR
            'dvr_duration'     => 'required|integer|min:60|max:86400',
            'segment_duration' => 'required|integer|min:2|max:30',

            // Recording
            'record_duration'  => 'required|integer|min:0|max:86400',

            'check_interval'   => 'required|integer|min:1|max:60',
            'max_retries'      => 'required|integer|min:0|max:20',
        ];
    }
}
