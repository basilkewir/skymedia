<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Recording;
use App\Models\User;
use App\Services\DVRService;
use App\Services\FFmpegService;
use App\Services\PlayoutService;
use App\Services\RecordingService;
use App\Services\StreamManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChannelController extends Controller
{
    public function __construct(
        protected FFmpegService $ffmpeg,
        protected StreamManager $manager,
        protected DVRService $dvr,
        protected PlayoutService $playout,
        protected RecordingService $recording,
    ) {}

    public function index(): Response
    {
        $user = auth()->user();
        $query = Channel::withCount('dvrSegments')
            ->withCount('streamLogs')
            ->withSum('dvrSegments', 'duration')
            ->withSum('dvrSegments', 'filesize')
            ->latest();

        // Non-admin users only see their own channels
        if (!$user->is_admin ?? false) {
            $query->where('user_id', $user->id);
        }

        $channels = $query->paginate(20);

        if (! ($user->is_admin ?? false)) {
            $channels->getCollection()->each->makeHidden([
                'push_url', 'push_stream_key', 'push_username', 'push_password',
                'push_protocol', 'push_pid', 'push_status',
                'storage_quota_bytes', 'storage_used_bytes',
                'dvr_segments_count', 'dvr_segments_sum_duration', 'dvr_segments_sum_filesize',
            ]);
        }

        return Inertia::render('Channels/Index', [
            'channels' => $channels,
            'isAdmin' => (bool) ($user->is_admin ?? false),
        ]);
    }

    public function create(): Response
    {
        abort_unless(auth()->user()->is_admin ?? false, 403);
        $users = User::orderBy('name')->get(['id', 'name', 'is_admin']);
        return Inertia::render('Channels/Create', [
            'users' => $users,
            'isAdmin' => auth()->user()->is_admin ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->is_admin ?? false, 403);
        $data = $request->validate($this->rules());

        // Convert GB to bytes — also handle clearing the quota (unlimited)
        if (!empty($data['storage_quota_gb']) && $data['storage_quota_gb'] > 0) {
            $data['storage_quota_bytes'] = (int) ($data['storage_quota_gb'] * 1024 * 1024 * 1024);
        } elseif (array_key_exists('storage_quota_gb', $data)) {
            $data['storage_quota_bytes'] = null;
        }
        unset($data['storage_quota_gb']);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['dvr_path'] = config('skymedia.dvr_base_path', storage_path('app/dvr')) . '/' . $data['slug'];
        $data['is_active'] = false;
        $data['stream_status'] = 'idle';
        $data['user_id'] = auth()->id();

        if (($data['ingest_mode'] ?? 'pull') === 'push') {
            $data['source_url'] = $data['source_url'] ?: 'push://listener';
            if (empty($data['rtmp_input_key'])) {
                $data['rtmp_input_key'] = Str::random(24);
            }
            $data['dvr_enabled'] = false;
            $data['record_duration'] = 0;
        }

        $channel = Channel::create($data);
        if ($channel->isPushIngest()) {
            $port = $channel->ingest_port;
            $portTaken = $port && Channel::where('ingest_port', $port)->where('id', '!=', $channel->id)->exists();
            if (! $port || $portTaken) {
                $channel->update(['ingest_port' => $this->availableIngestPort($channel->source_type, $channel->id)]);
            }
        }

        // Managed channels must immediately listen for OBS/vMix publishers.
        // A stopped listener presents as "Failed to connect to server".
        if ($channel->fresh()->isPushIngest()) {
            try {
                $started = $this->manager->startChannel($channel->fresh());
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("[Channel {$channel->id}] store startChannel: {$e->getMessage()}");
                $started = false;
            }

            if (! $started) {
                return redirect()->route('channels.show', $channel)
                    ->with('error', 'Channel created but the ingest listener failed to start. Check the channel status for details.');
            }
        }

        return redirect()->route('channels.show', $channel)->with('success', 'Channel created');
    }

    public function show(Channel $channel): Response
    {
        $this->ensureChannelAccess($channel);
        $isAdmin = (bool) (auth()->user()->is_admin ?? false);
        $channel->append(['published_ingest_url', 'published_ingest_server']);
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

        if (! $isAdmin && $channel->isPushIngest()) {
            unset($channel->dvr_total_duration, $channel->dvr_total_size, $channel->dvr_buffer_pct, $channel->dvr_segment_count);
            $channel->makeHidden([
                'push_url', 'push_stream_key', 'push_username', 'push_password',
                'push_protocol', 'push_video_codec', 'push_video_bitrate',
                'push_resolution', 'push_framerate', 'push_audio_codec',
                'push_audio_bitrate', 'push_audio_samplerate', 'push_audio_channels',
                'push_hls_segment_duration', 'push_hls_list_size', 'push_pid',
            ]);
            $channel->unsetRelation('recordings');
        }

        return Inertia::render('Channels/Show', [
            'channel' => $channel,
            'isAdmin' => $isAdmin,
            'previewUrl' => $channel->logo_media_id || $channel->ticker_enabled
                ? $this->brandedPreviewUrl($channel)
                : route('hls.serve', [$channel, 'output.m3u8']),
        ]);
    }

    public function edit(Channel $channel): Response
    {
        $this->ensureChannelAccess($channel);
        if ($channel->isPushIngest() && ! (auth()->user()->is_admin ?? false)) abort(403);
        $channel->append(['published_ingest_url', 'published_ingest_server']);
        $users = auth()->user()->is_admin ? User::orderBy('name')->get(['id', 'name', 'email']) : collect();
        $sources = $channel->channelSources()->orderBy('priority')->get();
        return Inertia::render('Channels/Edit', [
            'channel' => $channel,
            'users' => $users,
            'isAdmin' => auth()->user()->is_admin ?? false,
            'sources' => $sources,
            'currentSourceId' => $channel->current_source_id,
        ]);
    }

    public function update(Request $request, Channel $channel): RedirectResponse
    {
        $this->ensureChannelAccess($channel);
        if ($channel->isPushIngest() && ! (auth()->user()->is_admin ?? false)) abort(403);
        $dvrWasEnabled = $channel->dvr_enabled !== false;
        $data = $request->validate($this->rules(update: true));

        if (($data['ingest_mode'] ?? 'pull') === 'push') {
            $data['source_url'] = $data['source_url'] ?: 'push://listener';
            $data['rtmp_input_key'] = $channel->rtmp_input_key ?: Str::random(24);
            $data['ingest_port'] ??= $channel->ingest_port ?: $this->availableIngestPort($data['source_type'], $channel->id);
            $data['dvr_enabled'] = false;
            $data['record_duration'] = 0;
        }

        // Convert GB to bytes — also handle clearing the quota (unlimited)
        if (!empty($data['storage_quota_gb']) && $data['storage_quota_gb'] > 0) {
            $data['storage_quota_bytes'] = (int) ($data['storage_quota_gb'] * 1024 * 1024 * 1024);
        } elseif (array_key_exists('storage_quota_gb', $data)) {
            $data['storage_quota_bytes'] = null;
        }
        unset($data['storage_quota_gb']);

        // Non-admin users cannot change owner
        if (!(auth()->user()->is_admin ?? false)) {
            unset($data['user_id']);
        }

        $channel->update($data);

        $dvrChanged = $dvrWasEnabled !== ($channel->fresh()->dvr_enabled !== false);
        if ($dvrChanged && ! $channel->fresh()->dvr_enabled) {
            $this->dvr->purgeAll($channel->fresh());
        }
        if ($dvrChanged && $channel->fresh()->is_active) {
            $this->manager->restartChannel($channel->fresh());
        }

        return redirect()->route('channels.show', $channel)->with('success', 'Channel updated');
    }

    public function uploadFallbackVod(Request $request, Channel $channel): RedirectResponse
    {
        $this->ensureChannelAccess($channel);
        $request->validate([
            'fallback_vod' => 'required|file|max:2097152|mimes:mp4,mov,mkv,webm,ts,mpeg,mpg',
        ]);

        $file = $request->file('fallback_vod');
        $fileSize = $file->getSize();

        if ($channel->hasStorageQuota() && !$channel->canStore($fileSize)) {
            $available = $channel->storage_quota_bytes - $channel->storage_used_bytes;
            return back()->withErrors([
                'fallback_vod' => "Upload exceeds channel storage quota. Available: " . $this->formatBytes($available),
            ]);
        }

        $directory = $channel->dvr_directory;
        if (! is_dir($directory)) mkdir($directory, 0755, true);

        $extension = strtolower($file->getClientOriginalExtension() ?: 'mp4');
        $path = $directory . '/fallback_uploaded.' . $extension;
        foreach (glob($directory . '/fallback_uploaded.*') ?: [] as $old) @unlink($old);
        $file->move($directory, basename($path));

        $channel->update([
            'fallback_recording_path' => $path,
            'fallback_vod_name' => $file->getClientOriginalName(),
        ]);

        if ($channel->fresh()->storage_used_bytes !== null) {
            $channel->increment('storage_used_bytes', $fileSize);
        }

        if ($channel->playout_status === 'fallback') {
            $this->playout->switchToFallback($channel->fresh());
        }

        return back()->with('success', 'Fallback VOD uploaded');
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.2f %s', $bytes, $units[$i]);
    }

    public function removeFallbackVod(Channel $channel): RedirectResponse
    {
        $this->ensureChannelAccess($channel);
        $size = 0;
        if ($channel->fallback_vod_name && $channel->fallback_recording_path) {
            $size = filesize($channel->fallback_recording_path) ?? 0;
            @unlink($channel->fallback_recording_path);
        }
        $latestRecording = $channel->recordings()->where('status', 'completed')->first();
        $channel->update([
            'fallback_vod_name' => null,
            'fallback_recording_path' => $latestRecording?->filepath,
        ]);

        if ($size > 0) {
            $channel->decrement('storage_used_bytes', $size);
        }

        if ($channel->playout_status === 'fallback') {
            $this->playout->switchToFallback($channel->fresh());
        }

        return back()->with('success', 'Uploaded fallback removed');
    }

    public function destroy(Channel $channel): RedirectResponse
    {
        $this->ensureChannelAccess($channel);
        if ($channel->isPushIngest()) abort_unless(auth()->user()->is_admin ?? false, 403);
        $this->manager->stopChannel($channel);
        $channel->delete();

        return redirect()->route('channels.index')->with('success', 'Channel deleted');
    }

    public function clone(Channel $channel): RedirectResponse
    {
        abort_unless(auth()->user()->is_admin ?? false, 403);
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
        $clone->relay_pid = null;
        $clone->rtmp_input_key = $channel->isPushIngest() ? Str::random(24) : null;
        $clone->ingest_port = $channel->isPushIngest() ? $this->availableIngestPort($channel->source_type) : null;
        $clone->retry_count = 0;
        $clone->last_error = null;
        $clone->last_live_at = null;
        $clone->last_check_at = null;
        $clone->fallback_recording_path = null;
        $clone->fallback_vod_name = null;
        $clone->dvr_path = null; // will be auto-assigned based on new id
        $clone->save();

        return redirect()->route('channels.edit', $clone)
            ->with('success', "Channel cloned as '{$clone->name}' — edit and activate when ready");
    }

    public function toggle(Channel $channel): RedirectResponse
    {
        $this->ensureChannelAccess($channel);
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
        $this->ensureChannelAccess($channel);

        if ($channel->isPushIngest()) {
            $this->manager->restartChannel($channel);
        } else {
            $this->manager->refreshIngest($channel);
        }

        return back()->with('success', 'Channel restarted');
    }

    public function purgeDvr(Channel $channel): RedirectResponse
    {
        $n = $this->dvr->purgeAll($channel);

        return back()->with('success', "DVR cleared ({$n} segments removed)");
    }

    public function status(Channel $channel): JsonResponse
    {
        $this->ensureChannelAccess($channel);
        $channel->loadCount('dvrSegments');
        $channel->load(['recordings' => fn ($q) => $q->orderByDesc('started_at')->limit(10)]);

        $status = [
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
            'fallback_vod_name' => $channel->fallback_vod_name,
            'dvr_total_duration' => $this->dvr->totalDuration($channel),
            'dvr_total_size' => $this->dvr->totalSize($channel),
            'dvr_buffer_pct' => $this->dvr->bufferPercent($channel),
            'dvr_segment_count' => $this->dvr->segmentCount($channel),
            'recordings' => $channel->recordings,
        ];

        if ($channel->isPushIngest() && ! (auth()->user()->is_admin ?? false)) {
            unset(
                $status['playout_status'], $status['push_status'], $status['dvr_status'],
                $status['record_status'], $status['playout_pid'], $status['push_pid'],
                $status['record_pid'], $status['recordings'],
                $status['dvr_total_duration'], $status['dvr_total_size'],
                $status['dvr_buffer_pct'], $status['dvr_segment_count']
            );
        }

        return response()->json($status);
    }

    public function probe(Channel $channel): JsonResponse
    {
        $this->ensureChannelAccess($channel);
        if ($channel->isPushIngest()) abort_unless(auth()->user()->is_admin ?? false, 403);
        return response()->json($this->ffmpeg->probeStream($channel));
    }

    public function diagnose(Channel $channel): JsonResponse
    {
        $this->ensureChannelAccess($channel);
        if ($channel->isPushIngest()) abort_unless(auth()->user()->is_admin ?? false, 403);
        // Runs ffmpeg for 5 s and returns the full output — safe to call anytime
        return response()->json($this->ffmpeg->diagnoseIngest($channel));
    }

    public function logs(Channel $channel): JsonResponse|SymfonyResponse
    {
        $this->ensureChannelAccess($channel);
        if ($channel->isPushIngest()) abort_unless(auth()->user()->is_admin ?? false, 403);

        // If Inertia visits this URL directly (e.g. after login redirect),
        // redirect to the channel show page instead of returning raw JSON.
        if (request()->header('X-Inertia')) {
            return redirect()->route('channels.show', $channel);
        }

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

    // ── Recording management ────────────────────────────────────────────────

    public function startRecording(Channel $channel): JsonResponse
    {
        $this->ensureChannelAccess($channel);
        if ($channel->isPushIngest()) {
            return response()->json(['success' => false, 'error' => 'Push-ingest channels cannot record']);
        }

        $fresh = $channel->fresh();
        $liveM3u8 = $fresh->dvr_directory . '/live.m3u8';
        if (!file_exists($liveM3u8)) {
            return response()->json(['success' => false, 'error' => 'Channel is not live — no live.m3u8 available']);
        }

        if ($this->recording->isRunning($fresh)) {
            return response()->json(['success' => true, 'message' => 'Recording is already running']);
        }

        // Set a default record_duration if not configured
        if ($fresh->record_duration <= 0) {
            $fresh->update(['record_duration' => 3600]);
        }

        if ($this->recording->start($fresh)) {
            return response()->json(['success' => true, 'message' => 'Recording started']);
        }

        return response()->json(['success' => false, 'error' => 'Failed to start recording']);
    }

    public function stopRecording(Channel $channel): JsonResponse
    {
        $this->ensureChannelAccess($channel);
        $fresh = $channel->fresh();

        if (!$this->recording->isRunning($fresh)) {
            return response()->json(['success' => true, 'message' => 'No recording is running']);
        }

        $this->recording->stop($fresh);

        return response()->json(['success' => true, 'message' => 'Recording stopped']);
    }

    public function deleteRecording(Recording $recording): RedirectResponse
    {
        $channel = $recording->channel;
        $this->ensureChannelAccess($channel);

        if ($recording->status === 'recording') {
            return back()->withErrors(['recording' => 'Cannot delete an active recording — stop it first']);
        }

        if ($channel->fallback_recording_path === $recording->filepath) {
            $channel->update(['fallback_recording_path' => null]);
        }

        $size = filesize($recording->filepath) ?? 0;
        @unlink($recording->filepath);
        if ($size > 0) {
            $channel->decrement('storage_used_bytes', $size);
        }
        $recording->delete();

        return back()->with('success', 'Recording deleted');
    }

    // ── Validation rules ──────────────────────────────────────────────────────

    private function rules(bool $update = false): array
    {
        $srtIngest = request()->input('source_type') === 'srt';
        $ingestPortMin = $srtIngest ? 30000 : 20000;
        $ingestPortMax = $srtIngest ? 30099 : 20099;

        return [
            'name' => 'required|string|max:255',
            'slug' => $update ? 'nullable' : 'nullable|string|max:255|unique:channels,slug',
            'user_id' => 'nullable|exists:users,id',
            'source_type' => 'required|in:hls,udp,mpegts,rtmp,srt,youtube',
            'ingest_mode' => 'required|in:pull,push',
            'ingest_port' => "nullable|integer|min:{$ingestPortMin}|max:{$ingestPortMax}",
            'source_url' => 'nullable|required_if:ingest_mode,pull|string|max:1000',
            'youtube_cookies' => 'nullable|string|max:65535',
            'rtmp_input_key' => 'nullable|string|max:255',
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
            'segment_duration' => 'required|integer|min:1|max:30',
            'dvr_enabled' => 'required|boolean',
            // Storage quota (sent as GB from the form, converted to bytes below)
            'storage_quota_gb' => 'nullable|numeric|min:0',
            'storage_quota_bytes' => 'nullable|integer|min:1',
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

    private function availableIngestPort(string $protocol, ?int $exceptId = null): int
    {
        $port = $protocol === 'srt' ? 30000 : 20000;
        while (Channel::where('ingest_port', $port)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            $port++;
        }
        $maxPort = $protocol === 'srt' ? 30099 : 20099;
        if ($port > $maxPort) throw new \RuntimeException('No ingest ports are available');

        return $port;
    }

    private function brandedPreviewUrl(Channel $channel): string
    {
        $host = config('skymedia.server_ip');
        if ($host === 'localhost') {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        }
        return "http://{$host}:8081/hls-static/{$channel->slug}/index.m3u8";
    }

    private function ensureChannelAccess(Channel $channel): void
    {
        $user = auth()->user();
        abort_unless($user && (($user->is_admin ?? false) || $channel->user_id === $user->id), 403);
    }
}
