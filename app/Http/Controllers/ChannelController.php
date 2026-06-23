<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Recording;
use App\Services\DVRService;
use App\Services\FFmpegService;
use App\Services\StreamManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChannelController extends Controller
{
    public function __construct(
        protected FFmpegService $ffmpeg,
        protected StreamManager $manager,
        protected DVRService $dvr,
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

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['dvr_path'] = config('skymedia.dvr_base_path', storage_path('app/dvr')) . '/' . $data['slug'];
        $data['is_active'] = false;
        $data['stream_status'] = 'idle';

        Channel::create($data);

        return redirect()->route('channels.index')->with('success', 'Channel created');
    }

    public function show(Channel $channel): Response
    {
        $channel->load([
            'dvrSegments' => fn ($q) => $q->orderBy('sequence', 'desc')->limit(50),
            'recordings' => fn ($q) => $q->orderByDesc('started_at')->limit(10),
        ]);
        $channel->loadCount('streamLogs');

        // Append computed DVR stats as plain attributes for the Vue page
        $channel->dvr_total_duration = $this->dvr->totalDuration($channel);
        $channel->dvr_total_size = $this->dvr->totalSize($channel);
        $channel->dvr_buffer_pct = $this->dvr->bufferPercent($channel);
        $channel->dvr_segment_count = $this->dvr->segmentCount($channel);

        // Hint if the source URL looks like HTTP MPEG-TS despite being set to HLS
        $url = $channel->source_url;
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $channel->source_type_hint = null;
        if ($channel->source_type === 'hls'
            && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))
            && ! preg_match('/\.(m3u8?)(\?|#|$)/i', $path)) {
            $channel->source_type_hint = 'mpegts'; // URL looks like HTTP MPEG-TS, not HLS
        }

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

    public function clone(Channel $channel): RedirectResponse
    {
        $clone = $channel->replicate();
        $clone->name = $channel->name . ' (Copy)';
        $clone->slug = $channel->slug . '-copy';
        $clone->is_active = false;
        $clone->stream_status = 'idle';
        $clone->playout_status = 'idle';
        $clone->push_status = 'idle';
        $clone->dvr_status = 'idle';
        $clone->record_status = 'idle';
        $clone->source_live = false;
        $clone->pid = null;
        $clone->playout_pid = null;
        $clone->push_pid = null;
        $clone->record_pid = null;
        $clone->retry_count = 0;
        $clone->last_error = null;
        $clone->last_live_at = null;
        $clone->last_check_at = null;
        $clone->fallback_recording_path = null;
        $clone->dvr_path = null; // will be auto-assigned based on new id
        $clone->save();

        return redirect()->route('channels.edit', $clone)
            ->with('success', "Channel cloned as '{$clone->name}' — edit and activate when ready");
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

    public function purgeDvr(Channel $channel): RedirectResponse
    {
        $n = $this->dvr->purgeAll($channel);

        return back()->with('success', "DVR cleared ({$n} segments removed)");
    }

    public function status(Channel $channel): JsonResponse
    {
        $channel->loadCount('dvrSegments');
        $channel->load(['recordings' => fn ($q) => $q->orderByDesc('started_at')->limit(10)]);

        return response()->json([
            'stream_status' => $channel->stream_status,
            'playout_status' => $channel->playout_status,
            'push_status' => $channel->push_status,
            'dvr_status' => $channel->dvr_status,
            'record_status' => $channel->record_status,
            'source_live' => $channel->source_live,
            'pid' => $channel->pid,
            'playout_pid' => $channel->playout_pid,
            'push_pid' => $channel->push_pid,
            'record_pid' => $channel->record_pid,
            'last_live_at' => $channel->last_live_at?->toISOString(),
            'fallback_recording_path' => $channel->fallback_recording_path,
            'dvr_total_duration' => $this->dvr->totalDuration($channel),
            'dvr_total_size' => $this->dvr->totalSize($channel),
            'dvr_buffer_pct' => $this->dvr->bufferPercent($channel),
            'dvr_segment_count' => $this->dvr->segmentCount($channel),
            'recordings' => $channel->recordings,
        ]);
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

    /**
     * Stream a recording file for VOD playback.
     * Uses range requests for seeking in the browser video player.
     */
    public function playRecording(Recording $recording): StreamedResponse|\Illuminate\Http\Response
    {
        $filepath = $recording->filepath;

        if (! file_exists($filepath) || filesize($filepath) < 1024) {
            return response('Recording file not found', 404);
        }

        $size = filesize($filepath);
        $mime = 'video/mp4';

        // Range request support (browser seeks)
        $range = request()->header('Range');
        if ($range) {
            $parts = explode('-', str_replace('bytes=', '', $range));
            $start = (int) ($parts[0] ?? 0);
            $end = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : $size - 1;
            $length = $end - $start + 1;

            return response()->stream(function () use ($filepath, $start, $length) {
                $handle = fopen($filepath, 'rb');
                fseek($handle, $start);
                $remaining = $length;
                while ($remaining > 0 && ! feof($handle)) {
                    $chunk = fread($handle, min(8192, $remaining));
                    echo $chunk;
                    $remaining -= strlen($chunk);
                    flush();
                }
                fclose($handle);
            }, 206, [
                'Content-Type' => $mime,
                'Content-Length' => $length,
                'Content-Range' => "bytes {$start}-{$end}/{$size}",
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'no-cache',
            ]);
        }

        return response()->stream(function () use ($filepath) {
            $handle = fopen($filepath, 'rb');
            while (! feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => $size,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache',
        ]);
    }

    // ── Validation rules ──────────────────────────────────────────────────────

    private function rules(bool $update = false): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => $update ? 'nullable' : 'nullable|string|max:255|unique:channels,slug',
            'source_type' => 'required|in:hls,udp,mpegts,rtmp,srt',
            'source_url' => 'required|string|max:1000',
            'push_protocol' => 'required|in:rtmp,srt,hls',
            'push_url' => 'required|string|max:500',
            'push_stream_key' => 'required|string|max:255',
            'push_username' => 'nullable|string|max:255',
            'push_password' => 'nullable|string|max:255',
            // Video
            'push_video_codec' => 'required|in:copy,h264,h265,vp8,vp9',
            'push_video_bitrate' => 'nullable|integer|min:100|max:50000',
            'push_resolution' => 'nullable|string|max:30',
            'push_framerate' => 'nullable|integer|min:1|max:60',
            // Audio
            'push_audio_codec' => 'required|in:copy,aac,mp3,opus,ac3',
            'push_audio_bitrate' => 'nullable|integer|min:32|max:512',
            'push_audio_samplerate' => 'nullable|integer|in:22050,32000,44100,48000',
            'push_audio_channels' => 'nullable|integer|in:1,2,6',
            // DVR
            'dvr_duration' => 'required|integer|min:60|max:86400',
            'segment_duration' => 'required|integer|min:2|max:30',
            // Recording
            'record_duration' => 'required|integer|min:0|max:86400',
            'keep_recordings' => 'nullable|integer|min:1|max:10',
            // Locale
            'timezone' => 'nullable|string|max:50',
            'locale' => 'nullable|string|max:10',
            // Behaviour
            'check_interval' => 'required|integer|min:1|max:60',
            'max_retries' => 'required|integer|min:0|max:20',
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
