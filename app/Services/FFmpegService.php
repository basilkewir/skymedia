<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\PushDestination;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FFmpegService
{
    protected string $ffmpegBin;

    protected string $ffprobeBin;

    public function __construct(protected YoutubeService $youtube)
    {
        $this->ffmpegBin = config('skymedia.ffmpeg_binary', 'ffmpeg');
        $this->ffprobeBin = config('skymedia.ffprobe_binary', 'ffprobe');
    }

    public function getBin(): string
    {
        return $this->ffmpegBin;
    }

    protected function srtLatencyMs(): int
    {
        return (int) (Setting::get('srt_latency')
            ?? config('skymedia.srt_latency', 200));
    }

    /**
     * Browser-like User-Agent for HTTP(S) sources. Many CDNs/IPTV providers
     * block the default FFmpeg UA or require a common browser UA.
     */
    protected function httpUserAgent(): ?string
    {
        $ua = trim((string) config('skymedia.http_user_agent', ''));

        return $ua !== '' ? $ua : null;
    }

    /**
     * True when the URL uses HTTP(S).
     */
    protected function isHttpUrl(string $url): bool
    {
        return str_starts_with(strtolower($url), 'http://')
            || str_starts_with(strtolower($url), 'https://');
    }

    /**
     * Resolve an HLS master playlist to a concrete variant playlist URL.
     *
     * Some HLS endpoints serve a master playlist that FFmpeg probes by opening
     * every variant at once. That behaviour can trigger rate limiting or session
     * errors on certain CDNs (e.g. Nimble Streamer). By resolving the master
     * playlist ourselves and handing FFmpeg a single media playlist, ingest
     * becomes far more reliable.
     *
     * Returns the original URL if it is already a media playlist or if the
     * master playlist cannot be parsed.
     */
    public function resolveHlsUrl(string $url): string
    {
        if (! $this->isHttpUrl($url)) {
            return $url;
        }

        try {
            $options = ['allow_redirects' => true];
            if (config('skymedia.hls_tls_verify', false) === false) {
                $options['verify'] = false;
            }

            $request = Http::timeout(15)
                ->withOptions($options);

            $ua = $this->httpUserAgent();
            if ($ua) {
                $request = $request->withUserAgent($ua);
            }

            $response = $request->get($url);

            if (! $response->successful()) {
                return $url;
            }

            $body = trim($response->body());

            // Not a master playlist — leave it alone
            if (! str_contains($body, '#EXT-X-STREAM-INF')) {
                return $url;
            }

            $baseUrl = $url;
            $variants = [];
            $lines = explode("\n", $body);
            $currentBandwidth = 0;

            foreach ($lines as $line) {
                $line = trim($line);

                if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                    preg_match('/BANDWIDTH=(\d+)/', $line, $m);
                    $currentBandwidth = (int) ($m[1] ?? 0);
                } elseif ($line !== '' && ! str_starts_with($line, '#')) {
                    $variants[] = [
                        'url' => $this->resolveUrl($line, $baseUrl),
                        'bandwidth' => $currentBandwidth,
                    ];
                    $currentBandwidth = 0;
                }
            }

            if ($variants === []) {
                return $url;
            }

            // Pick the highest bandwidth variant. If bandwidth is missing for all,
            // usort keeps the original order, so the first variant is used.
            usort($variants, fn ($a, $b) => $b['bandwidth'] <=> $a['bandwidth']);

            $resolved = $variants[0]['url'];
            Log::debug("Resolved HLS master playlist [{$url}] -> [{$resolved}]");

            return $resolved;
        } catch (\Throwable $e) {
            Log::debug("HLS master playlist resolution failed [{$url}]: {$e->getMessage()}");

            return $url;
        }
    }

    /**
     * Returns -tls_verify 0 when TLS verification is disabled in config.
     * This is needed for many IPTV/HLS endpoints with non-standard certs.
     * Only applied to HTTPS inputs; plain HTTP has no TLS context and FFmpeg
     * reports "Option tls_verify not found" if the flag is used there.
     */
    protected function tlsVerifyFlag(Channel $channel): array
    {
        if ($channel->source_type === 'hls'
            && str_starts_with(strtolower($channel->source_url), 'https://')
            && config('skymedia.hls_tls_verify', false) === false) {
            return ['-tls_verify', '0'];
        }

        return [];
    }

    /**
     * Return the URL that FFmpeg should actually read: master HLS playlists
     * are resolved to a single variant, everything else passes through.
     */
    protected function effectiveSourceUrl(Channel $channel): string
    {
        $url = $channel->source_url;

        if ($channel->source_type === 'youtube') {
            try {
                return $this->youtube->resolveHlsUrl($channel);
            } catch (\Throwable $e) {
                Log::warning("[YouTube] URL resolution failed for channel {$channel->id}: {$e->getMessage()}");

                return $url; // fall through — ffmpeg will fail with a clear error
            }
        }

        if ($channel->source_type === 'hls' && $this->isHttpUrl($url)) {
            return $this->resolveHlsUrl($url);
        }

        return $url;
    }

    /**
     * Resolve a possibly-relative URL against a base URL.
     */
    protected function resolveUrl(string $url, string $base): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $baseInfo = parse_url($base);
        if ($baseInfo === false) {
            return $url;
        }

        $scheme = $baseInfo['scheme'] ?? 'https';
        $host = $baseInfo['host'] ?? '';
        $port = isset($baseInfo['port']) ? ':' . $baseInfo['port'] : '';

        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$port}{$url}";
        }

        $path = $baseInfo['path'] ?? '/';
        $dir = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '/';

        return "{$scheme}://{$host}{$port}{$dir}{$url}";
    }

    // ===================================================================
    //  COMMAND BUILDERS
    // ===================================================================

    /**
     * INGEST: source → HLS segments on disk (DVR buffer)
     *
     * Rules:
     * - Always stream copy on ingest (no re-encoding, minimal CPU)
     * - hls_list_size 0  → ffmpeg never trims the playlist
     * - delete_segments  → ffmpeg removes old .ts but we also enforce via DVRService
     * - omit_endlist     → playlist stays "live" (no EXT-X-ENDLIST)
     * - allowed_extensions ONLY added for genuine HLS sources
     */
    public function buildIngestCommand(Channel $channel): array
    {
        $dvrDir = $channel->dvr_directory;
        $m3u8 = "{$dvrDir}/live.m3u8";
        $segPattern = "{$dvrDir}/seg_%05d.ts";

        // Managed RTMP/SRT publishers are relay-only. Keep only the small HLS
        // working buffer required for preview/fallback switching.
        $dvrEnabled = ! $channel->isPushIngest() && $channel->dvr_enabled !== false;

        return array_merge(
            [
                $this->ffmpegBin,
                '-y',
                '-loglevel', 'warning',
                '-stats',
            ],
            $this->inputFlags($channel),
            [
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-f',                    'hls',
            '-hls_time',             (string) max(1, (int) $channel->segment_duration),
            '-hls_list_size',        $dvrEnabled ? '15' : '10',
            '-hls_flags',            'delete_segments+omit_endlist+append_list',
            '-hls_delete_threshold', $dvrEnabled ? '4' : '3',
            '-hls_segment_type',     'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache',      '0',
            '-hls_start_number_source', 'epoch',
            '-max_muxing_queue_size', '4096',
            // LLOD v3 — force keyframes every 2 seconds for clean
            // segment splits and instant playback on the player side.
            '-force_key_frames',     'expr:gte(t,n_forced*2)',
            // LLOD v3 — skip B-frames to reduce decoder latency.
            '-bf',                   '0',
            $m3u8,
            ]
        );
    }

    /**
     * PUSH: reads the stable output playlist → encode → RTMP/SRT.
     *
     * Design goals:
     * - NEVER stop pushing. If the connection drops, ffmpeg must reconnect.
     * - output.m3u8 is a local symlink — no HTTP reconnect flags needed for input.
     * - -re paces output at live rate to prevent RTMP server buffer overflow.
     * - -reconnect_at_eof + -reconnect_streamed handle the symlink swap between
     *   live.m3u8 and playout_X.m3u8 without restarting the push process.
     * - -max_reload prevents ffmpeg from giving up when the playlist is briefly
     *   unavailable during a symlink swap (atomic rename, but still).
     * - -timeout on output side keeps the RTMP/SRT connection alive.
     */
    public function buildPushCommand(Channel $channel, string $playlistPath, ?string $protocol = null, ?PushDestination $destination = null): array
    {
        $protocol ??= $channel->push_protocol;

        $fps = $channel->push_framerate ?? 25;
        $bitrate = (int) ($channel->push_video_bitrate ?? 2000);

        $cmd = [
            $this->ffmpegBin, '-y', '-loglevel', 'warning', '-stats',
            '-re',
            '-fflags',             '+genpts+discardcorrupt+flush_packets',
            '-thread_queue_size',  '4096',
            '-probesize',          '5000000',
            '-analyzeduration',    '3000000',
            '-err_detect',         'ignore_err',
            '-live_start_index',   '-3',
            '-allowed_extensions', 'ALL',
            '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
            // Keep reading even when the playlist briefly disappears during
            // an atomic symlink swap (live ↔ fallback transition).
            '-max_reload',         '1000',
            '-m3u8_hold_counters', '1000',
            '-i',                  $playlistPath,
        ];

        // Push uses stream copy for video — branding is applied in PlayoutService.
        // Audio is re-encoded to AAC for RTMP/SRT to guarantee compatibility with
        // IPTV panels (Wowza, XUI ONE) which require AAC for HLS output.
        $cmd[] = '-max_muxing_queue_size';
        $cmd[] = '99999';
        $cmd[] = '-thread_queue_size';
        $cmd[] = '1024';
        $cmd[] = '-c:v';
        $cmd[] = 'copy';

        $audioBitrate = ((int) ($channel->push_audio_bitrate ?? 128)) . 'k';
        $audioSamplerate = (string) (int) ($channel->push_audio_samplerate ?? 48000);
        $audioChannels = (string) (int) ($channel->push_audio_channels ?? 2);

        $pushUrl = $destination ? $this->buildDestinationPushUrl($destination) : $this->pushUrl($channel);

        if ($protocol === 'srt') {
            $cmd[] = '-c:a';
            $cmd[] = 'aac';
            $cmd[] = '-b:a';
            $cmd[] = $audioBitrate;
            $cmd[] = '-ar';
            $cmd[] = $audioSamplerate;
            $cmd[] = '-ac';
            $cmd[] = $audioChannels;
            $cmd[] = '-f';
            $cmd[] = 'mpegts';
            // LLOD v3 — SRT low-latency flags.
            $cmd[] = '-flags';
            $cmd[] = '+global_header';
            $cmd[] = '-bsf:v';
            $cmd[] = 'h264_mp4toannexb';
            $cmd[] = '-force_key_frames';
            $cmd[] = 'expr:gte(t,n_forced*2)';
            $cmd[] = '-bf';
            $cmd[] = '0';
            $cmd[] = $pushUrl;
        } elseif ($protocol === 'hls') {
            $cmd[] = '-c:a';
            $cmd[] = 'aac';
            $cmd[] = '-b:a';
            $cmd[] = $audioBitrate;
            $cmd[] = '-ar';
            $cmd[] = $audioSamplerate;
            $cmd[] = '-ac';
            $cmd[] = $audioChannels;
            $cmd = array_merge($cmd, $this->hlsOutputFlags($channel, $destination));
            $cmd[] = $pushUrl;
        } else {
            // RTMP LLOD v3: Low-Latency On-Demand optimisations for fast
            // Time-To-First-Frame on external IPTV panels / media servers.
            $cmd[] = '-c:a';
            $cmd[] = 'aac';
            $cmd[] = '-b:a';
            $cmd[] = $audioBitrate;
            $cmd[] = '-ar';
            $cmd[] = $audioSamplerate;
            $cmd[] = '-ac';
            $cmd[] = $audioChannels;
            $cmd[] = '-f';
            $cmd[] = 'flv';
            $cmd[] = '-rtmp_live';
            $cmd[] = 'live';
            $cmd[] = '-rtmp_buffer';
            $cmd[] = '3000';
            $cmd[] = '-rtmp_conn';
            $cmd[] = 'O:1';
            // LLOD v3 — instant handshake: skip FLV duration recalculation
            // so the receiving server gets the stream header immediately.
            $cmd[] = '-flvflags';
            $cmd[] = 'no_duration_filesize';
            // LLOD v3 — store codec config in the stream header so the
            // player can decode the first frame without extra requests.
            $cmd[] = '-flags';
            $cmd[] = '+global_header';
            // LLOD v3 — Annex B bitstream filter ensures correct TS framing
            // inside FLV, preventing buffering delays on the receiving end.
            $cmd[] = '-bsf:v';
            $cmd[] = 'h264_mp4toannexb';
            // LLOD v3 — force keyframes every 2 seconds so the receiving
            // server can split segments cleanly without transcoding.
            $cmd[] = '-force_key_frames';
            $cmd[] = 'expr:gte(t,n_forced*2)';
            $cmd[] = $pushUrl;
        }

        return $cmd;
    }

    public function brandingFlags(Channel $channel): array
    {
        $channel->loadMissing('logoMedia');
        $logo = $channel->logoMedia;
        $hasLogo = $logo && file_exists($logo->filepath);
        $hasTicker = $channel->ticker_enabled && trim((string) $channel->ticker_text) !== '';
        if (! $hasLogo && ! $hasTicker) {
            return ['inputs' => [], 'video' => []];
        }

        $inputs = $hasLogo ? ['-loop', '1', '-i', $logo->filepath] : [];
        $position = match ($channel->logo_position) {
            'top-left' => '20:20', 'bottom-left' => '20:H-h-20',
            'bottom-right' => 'W-w-20:H-h-20', default => 'W-w-20:20',
        };
        $text = str_replace(['\\', ':', "'", '%'], ['\\\\', '\\:', "\\'", '\\%'], (string) $channel->ticker_text);
        $ticker = "drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf:text='{$text}':fontcolor=white:fontsize=28:box=1:boxcolor=black@0.65:boxborderw=10:x=w-mod(t*100\,w+tw):y=h-th-25";

        $fps = $channel->push_framerate ?? 25;
        $bitrate = (int) ($channel->push_video_bitrate ?? 2000);
        $encodeBase = ['-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'zerolatency', '-pix_fmt', 'yuv420p'];
        $rateControl = ['-b:v', $bitrate . 'k', '-maxrate', ((int) ($bitrate * 1.2)) . 'k', '-bufsize', ((int) ($bitrate * 2)) . 'k'];
        $gopFlags = ['-g', (string) ($fps * 2), '-keyint_min', (string) $fps, '-sc_threshold', '0', '-threads', '0'];

        if ($hasLogo) {
            $filter = "[1:v][0:v]scale2ref=w=main_w*0.15:h=-1[logo][base];[base][logo]overlay={$position}[branded]";
            $filter .= $hasTicker ? ";[branded]{$ticker}[vout]" : ';[branded]null[vout]';
            $video = array_merge(
                ['-filter_complex', $filter, '-map', '[vout]', '-map', '0:a?'],
                $encodeBase, $rateControl, $gopFlags
            );
        } else {
            $video = array_merge(
                ['-vf', $ticker],
                $encodeBase, $rateControl, $gopFlags
            );
        }

        return ['inputs' => $inputs, 'video' => $video];
    }

    /**
     * DVR LOOP: concat.txt → encode → RTMP/SRT (looping)
     * Kept for manual operator use via DVR playback.
     */
    public function buildDvrPlaybackCommand(Channel $channel): array
    {
        return array_merge(
            [
                $this->ffmpegBin, '-y', '-loglevel', 'warning', '-stats',
                '-stream_loop', '-1',
                '-safe', '0',
                '-f', 'concat',
                '-re',
                '-i', $channel->dvr_directory . '/concat.txt',
            ],
            $this->videoEncodeFlags($channel),
            $this->audioEncodeFlags($channel),
            ['-f', $channel->push_protocol === 'srt' ? 'mpegts' : 'flv'],
            $channel->push_protocol === 'rtmp' ? [
                // LLOD v3 — instant handshake for the receiving server.
                '-flvflags', 'no_duration_filesize',
                '-flags',    '+global_header',
                '-bsf:v',    'h264_mp4toannexb',
                '-force_key_frames', 'expr:gte(t,n_forced*2)',
                '-bf',       '0',
            ] : [],
            [$this->pushUrl($channel)]
        );
    }

    // ===================================================================
    //  PROCESS MANAGEMENT
    // ===================================================================

    /**
     * Launch an ffmpeg process in the background.
     * For push-ingest RTMP listeners, wraps ffmpeg in a shell loop so the
     * listener restarts immediately after the encoder disconnects — the port
     * is never released and vMix/OBS can reconnect without any gap.
     * Throws \RuntimeException with full ffmpeg stderr on failure.
     */
    public function startProcess(array $command, string $pidFile, string $logFile, int $stabiliseSeconds = 3): int
    {
        foreach ([dirname($logFile), dirname($pidFile)] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Rotate log when it exceeds 200 KB
        if (file_exists($logFile) && filesize($logFile) > 204_800) {
            rename($logFile, $logFile . '.1');
        }

        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $path = '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin';

        // For RTMP listeners (-listen 1): wrap in a shell loop so ffmpeg
        // restarts immediately after the encoder disconnects. This keeps the
        // port bound at all times — vMix/OBS sees the server as always up.
        // The loop PID is what we track; killing it stops both the loop and
        // any running ffmpeg child.
        $isRtmpListener = in_array('-listen', $command) && in_array('1', $command);
        if ($isRtmpListener) {
            // Write a sentinel file so the loop knows when to stop cleanly
            $stopFile = $pidFile . '.stop';
            @unlink($stopFile);
            $shell = "export PATH={$path}:\$PATH; ("
                . 'while [ ! -f ' . escapeshellarg($stopFile) . ' ]; do '
                . "{$escaped} >> " . escapeshellarg($logFile) . ' 2>&1; '
                . '[ ! -f ' . escapeshellarg($stopFile) . ' ] && sleep 1; '
                . 'done'
                . ') & echo $!';
        } else {
            $shell = "export PATH={$path}:\$PATH; nohup {$escaped} >> "
                     . escapeshellarg($logFile) . ' 2>&1 & echo $!';
        }

        $pid = (int) trim((string) shell_exec($shell));

        if ($pid <= 0) {
            $error = $this->readLogTail($logFile, 30);
            throw new \RuntimeException("ffmpeg did not start (PID=0).\n{$error}");
        }

        file_put_contents($pidFile, $pid);

        // Wait up to $stabiliseSeconds for process to stabilise
        $checks = $stabiliseSeconds * 2; // 500ms per check
        $minAlive = max(2, (int) ($stabiliseSeconds * 0.6)); // must survive 60% of window
        $alive = false;
        for ($i = 0; $i < $checks; $i++) {
            usleep(500_000);
            if (! $this->isRunning($pid)) {
                break;
            }
            if ($i >= $minAlive) {
                $alive = true;
                break;
            }
        }

        if (! $alive) {
            $this->clearPid($pidFile);
            $error = $this->readLogTail($logFile, 40);
            throw new \RuntimeException("ffmpeg exited immediately.\n{$error}");
        }

        return $pid;
    }

    public function stopProcess(int $pid, int $timeoutSeconds = 6): void
    {
        if ($pid <= 0) {
            return;
        }
        // Write sentinel stop file for any listener loop using this PID file.
        // We don't know the pidFile path here, so we search for it.
        foreach (glob(storage_path('app/pids/*.pid')) ?: [] as $pf) {
            if ((int) trim((string) @file_get_contents($pf)) === $pid) {
                @touch($pf . '.stop');
                break;
            }
        }
        exec("kill -TERM {$pid} 2>/dev/null");
        // Also kill any ffmpeg child of this loop process
        exec("pkill -TERM -P {$pid} 2>/dev/null");
        $maxWaits = $timeoutSeconds * 10; // 100 ms per check
        $w = 0;
        while ($this->isRunning($pid) && $w++ < $maxWaits) {
            usleep(100_000);
        }
        if ($this->isRunning($pid)) {
            exec("kill -KILL {$pid} 2>/dev/null");
            exec("pkill -KILL -P {$pid} 2>/dev/null");
        }
    }

    public function isRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (is_dir("/proc/{$pid}")) {
            return true;
        }
        exec("ps -p {$pid} -o pid= 2>/dev/null", $out, $code);

        return $code === 0 && ! empty(trim(implode('', $out)));
    }

    // ===================================================================
    //  DIAGNOSTICS
    // ===================================================================

    /**
     * Run ingest for 5 s and capture all output for the admin UI.
     */
    public function diagnoseIngest(Channel $channel): array
    {
        $dvrDir = $channel->dvr_directory;
        if (! is_dir($dvrDir)) {
            mkdir($dvrDir, 0755, true);
        }

        $cmd = $this->buildIngestCommand($channel);
        // Resolve full binary path for Symfony Process (different PATH from shell_exec)
        $resolved = trim((string) shell_exec('which ' . escapeshellarg($this->ffmpegBin) . ' 2>/dev/null'))
                 ?: trim((string) shell_exec('command -v ' . escapeshellarg($this->ffmpegBin) . ' 2>/dev/null'));
        if ($resolved) {
            $cmd[0] = $resolved;
        }
        foreach ($cmd as &$v) {
            if ($v === 'warning') {
                $v = 'info';
                break;
            }
        }
        unset($v);
        array_splice($cmd, -1, 0, ['-t', '5']);

        $proc = new Process($cmd);
        $proc->setTimeout(20);
        $proc->run();

        return [
            'command' => implode(' ', $cmd),
            'exit_code' => $proc->getExitCode(),
            'stdout' => $proc->getOutput(),
            'stderr' => $proc->getErrorOutput(),
            'success' => $proc->isSuccessful(),
        ];
    }

    // ===================================================================
    //  SOURCE HEALTH
    // ===================================================================

    public function checkSourceHealth(Channel $channel): bool
    {
        if ($channel->isPushIngest()) {
            return false;
        }

        try {
            $url = $this->effectiveSourceUrl($channel);

            $args = [
                $this->ffprobeBin,
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_streams',
                '-timeout', '10000000',
            ];

            // Add source-type specific flags
            $this->addProbeInputFlags($args, $channel);

            $args[] = $url;

            $proc = new Process($args);
            $proc->setTimeout(15);
            $proc->run();

            if (! $proc->isSuccessful()) {
                return false;
            }
            $data = json_decode($proc->getOutput(), true);

            return ! empty($data['streams']);
        } catch (\Throwable $e) {
            Log::debug("Health check [{$channel->name}]: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Build the ffprobe command for a channel.
     */
    public function buildProbeCommand(Channel $channel): array
    {
        $url = $this->effectiveSourceUrl($channel);

        $args = [
            $this->ffprobeBin,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format', '-show_streams',
            '-timeout', '8000000',
        ];

        $this->addProbeInputFlags($args, $channel);
        $args[] = $url;

        return $args;
    }

    public function probeStream(Channel $channel): array
    {
        if ($channel->isPushIngest()) {
            return ['error' => 'Push ingest listeners are monitored from received media segments.'];
        }

        $proc = new Process($this->buildProbeCommand($channel));
        $proc->setTimeout(15);
        $proc->run();

        if (! $proc->isSuccessful()) {
            return ['error' => $proc->getErrorOutput()];
        }

        return json_decode($proc->getOutput(), true) ?? [];
    }

    // ===================================================================
    //  HLS READINESS
    // ===================================================================

    /**
     * Check if the current output playlist has enough segments.
     * Reads output.m3u8 (symlink) and checks the corresponding segment files.
     */
    public function hlsReady(Channel $channel, int $minSegments = 2): bool
    {
        $dvrDir = $channel->dvr_directory;
        $m3u8 = $dvrDir . '/output.m3u8';

        // output.m3u8 is a symlink — resolve the target playlist
        $target = is_link($m3u8) ? readlink($m3u8) : 'live.m3u8';
        $playlist = $dvrDir . '/' . $target;

        if (! file_exists($playlist)) {
            return false;
        }

        // Check the right segment pattern based on playlist
        $segPattern = str_contains($target, 'playout') ? 'playout_*.ts' : 'seg_*.ts';

        return count(glob($dvrDir . '/' . $segPattern) ?: []) >= $minSegments;
    }

    /** Check the ingest playlist directly, even while output.m3u8 is on fallback. */
    public function liveHlsReady(Channel $channel, int $minSegments = 2): bool
    {
        $dvrDir = $channel->dvr_directory;
        if (! file_exists($dvrDir . '/live.m3u8')) {
            return false;
        }

        return count(glob($dvrDir . '/seg_*.ts') ?: []) >= $minSegments;
    }

    /**
     * True when the DVR has segments written within the last $seconds seconds.
     * This is used as a secondary health signal when ffprobe-based health
     * checks fail for streams that are actually ingesting fine.
     */
    public function hasRecentSegments(Channel $channel, int $seconds = 15): bool
    {
        $dvrDir = $channel->dvr_directory;
        $cutoff = time() - $seconds;

        foreach (glob($dvrDir . '/seg_*.ts') ?: [] as $seg) {
            if (filemtime($seg) >= $cutoff) {
                return true;
            }
        }

        return false;
    }

    // ===================================================================
    //  PID / FILE HELPERS
    // ===================================================================

    public function pidFile(Channel $channel, string $type = 'ingest'): string
    {
        $dir = storage_path('app/pids');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return "{$dir}/{$type}_{$channel->id}.pid";
    }

    public function logFile(Channel $channel, string $type = 'ingest'): string
    {
        $dir = config('skymedia.log_base_path', storage_path('logs/streams'));
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return "{$dir}/{$type}_{$channel->id}.log";
    }

    public function readPid(string $pidFile): int
    {
        if (! file_exists($pidFile)) {
            return 0;
        }

        return (int) trim((string) file_get_contents($pidFile));
    }

    public function clearPid(string $pidFile): void
    {
        @unlink($pidFile);
        @unlink($pidFile . '.stop');
    }

    public function readLogTail(string $logFile, int $lines = 50): string
    {
        if (! file_exists($logFile)) {
            return "(log not found: {$logFile})";
        }
        $out = [];
        exec("tail -n {$lines} " . escapeshellarg($logFile) . ' 2>/dev/null', $out);

        return implode("\n", $out);
    }

    /**
     * Quick validity check for a media file. Retries a few times because a
     * recording that has just been stopped may still be writing its moov atom.
     */
    public function isPlayableFile(string $path, int $retries = 3): bool
    {
        if (! file_exists($path) || filesize($path) < 1024) {
            return false;
        }

        $attempt = 0;
        while ($attempt <= $retries) {
            $proc = new Process([
                $this->ffprobeBin,
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $path,
            ]);
            $proc->setTimeout(15);
            $proc->run();

            $out = trim($proc->getOutput());
            if ($proc->isSuccessful() && is_numeric($out) && (float) $out > 0) {
                return true;
            }

            $attempt++;
            if ($attempt <= $retries) {
                sleep(1);
            }
        }

        return false;
    }

    // ===================================================================
    //  INTERNAL — INPUT FLAGS
    // ===================================================================

    /**
     * Returns the correct ffmpeg input flags for the channel source type.
     *
     * KEY RULES:
     * - allowed_extensions is HLS-ONLY (causes "Option not found" on all other demuxers)
     * - HTTP MPEG-TS (common IPTV format): URL is http/https but content is raw MPEG-TS
     *   detected when source_type is mpegts/udp/rtmp or URL has no .m3u8/.m3u extension
     * - -re (read at native frame rate) only for HLS pull — live UDP/SRT/RTMP push at their own rate
     */
    protected function inputFlags(Channel $channel): array
    {
        $url = $channel->isPushIngest() ? $channel->ingest_listen_url : $channel->source_url;
        $type = $channel->source_type;
        $probesize = '5000000';
        $analyze = '3000000';

        if ($channel->isPushIngest()) {
            $flags = [
                '-fflags', '+genpts+discardcorrupt',
                '-probesize', $probesize,
                '-analyzeduration', $analyze,
            ];
            if ($type === 'rtmp') {
                // -listen 1: ffmpeg binds the port and waits for a connection.
                // -timeout 5000000 (5s): after the encoder disconnects, ffmpeg
                // exits within 5s so the loop wrapper restarts it immediately
                // and re-binds the port. vMix/OBS can reconnect within seconds.
                // Previously 120s caused a 2-minute window where the port was
                // held by a dying process and encoders got "Failed to connect".
                array_push($flags, '-listen', '1', '-timeout', '5000000');
            } elseif ($type === 'srt') {
                $latency = $this->srtLatencyMs() * 1000;
                $separator = str_contains($url, '?') ? '&' : '?';
                $url .= "{$separator}latency={$latency}&mode=listener";
            }
            array_push($flags, '-i', $url);

            return $flags;
        }

        // Detect HTTP MPEG-TS: http(s) URL but NOT an HLS playlist
        $isHttpMpegts = $this->isHttpMpegts($url, $type);

        switch (true) {

            // YouTube live: resolved to HLS by YoutubeService — treat as HLS
            case $type === 'youtube':
                try {
                    $inputUrl = $this->youtube->resolveHlsUrl($channel);
                } catch (\Throwable $e) {
                    throw new \RuntimeException("YouTube URL resolution failed: {$e->getMessage()}");
                }
                $ua = $this->httpUserAgent()
                    ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

                return [
                    '-re',
                    '-fflags',             '+genpts+discardcorrupt',
                    '-probesize',          '5000000',
                    '-analyzeduration',    '3000000',
                    '-allowed_extensions', 'ALL',
                    '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
                    '-live_start_index',   '-3',
                    '-user_agent',         $ua,
                    '-timeout',            '15000000',
                    '-reconnect',          '1',
                    '-reconnect_streamed', '1',
                    '-reconnect_delay_max', '30',
                    '-i',                  $inputUrl,
                ];

                // HLS: http/https URLs ending in .m3u8/.m3u OR explicitly set to hls
            case $type === 'hls' && ! $isHttpMpegts:
                // Master playlists can make FFmpeg probe every variant at once,
                // which breaks on some CDNs. Resolve to a single media playlist.
                $inputUrl = $this->resolveHlsUrl($url);
                $isFile = str_starts_with($inputUrl, 'file://');

                $flags = [
                    '-re',
                    '-fflags', '+genpts+discardcorrupt',
                    '-probesize', $probesize,
                    '-analyzeduration', $analyze,
                    '-allowed_extensions', 'ALL',
                    '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
                    '-live_start_index', '-3',
                ];

                // Network-specific options are invalid for local file:// playlists
                if (! $isFile) {
                    $ua = $this->httpUserAgent();
                    if ($ua) {
                        $flags[] = '-user_agent';
                        $flags[] = $ua;
                    }

                    $flags = array_merge($flags, $this->tlsVerifyFlag($channel), [
                        '-timeout', '15000000',
                        '-reconnect', '1',
                        '-reconnect_streamed', '1',
                        '-reconnect_delay_max', '30',
                    ]);
                }

                $flags[] = '-i';
                $flags[] = $inputUrl;

                return $flags;

                // HTTP MPEG-TS (IPTV streams: http://host/user/pass/channel_id)
            case $isHttpMpegts:
                $flags = [
                    '-fflags',          '+genpts+discardcorrupt',
                    '-probesize',       $probesize,
                    '-analyzeduration', $analyze,
                    '-timeout',         '10000000',
                    '-reconnect',       '1',
                    '-reconnect_at_eof', '1',
                    '-reconnect_streamed', '1',
                    '-reconnect_delay_max', '5',
                ];

                // Use a browser UA — many IPTV providers block the default ffmpeg UA
                $ua = $this->httpUserAgent()
                    ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
                $flags[] = '-user_agent';
                $flags[] = $ua;

                $flags[] = '-i';
                $flags[] = $url;

                return $flags;

                // UDP multicast / MPEG-TS over UDP
            case $type === 'udp' || $type === 'mpegts':
                return [
                    '-fflags',          '+genpts+discardcorrupt',
                    '-probesize',       $probesize,
                    '-analyzeduration', $analyze,
                    '-timeout',         '10000000',
                    '-i',               $url,
                ];

                // SRT
            case $type === 'srt':
                $latency = $this->srtLatencyMs() * 1000;
                $srtUrl = 'srt://' . $this->parseSrtUrl($url)
                         . "?timeout=8000000&latency={$latency}";

                return [
                    '-fflags',          '+genpts+discardcorrupt',
                    '-probesize',       $probesize,
                    '-analyzeduration', $analyze,
                    '-i',               $srtUrl,
                ];

                // RTMP and anything else
            default:
                return [
                    '-fflags',          '+genpts+discardcorrupt',
                    '-probesize',       $probesize,
                    '-analyzeduration', $analyze,
                    '-timeout',         '10000000',
                    '-i',               $url,
                ];
        }
    }

    /**
     * True when the channel source is an HTTP MPEG-TS IPTV stream.
     * These streams must never be probed with ffprobe during monitoring —
     * doing so opens a second TCP connection which providers detect as a
     * duplicate session and terminate the primary ingest connection.
     */
    public function isIptvStream(Channel $channel): bool
    {
        if ($channel->isPushIngest()) {
            return false;
        }
        if ($channel->source_type === 'youtube') {
            return true;
        } // never ffprobe YouTube URLs

        return $this->isHttpMpegts($channel->source_url, $channel->source_type);
    }

    /**
     * Detect HTTP MPEG-TS streams:
     * - http/https URL that does NOT end in .m3u8 or .m3u
     * - OR source_type is mpegts/udp but URL starts with http
     */
    protected function isHttpMpegts(string $url, string $type): bool
    {
        $isHttp = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
        if (! $isHttp) {
            return false;
        }

        // Explicit non-HLS types over HTTP
        if (in_array($type, ['mpegts', 'udp', 'rtmp'])) {
            return true;
        }

        // HLS type but URL has no playlist extension = IPTV MPEG-TS
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $hasPlaylistExt = preg_match('/\.(m3u8?|ts)([\?#]|$)/i', $path);
        if ($type === 'hls' && ! $hasPlaylistExt) {
            return true;
        }

        return false;
    }

    /**
     * Add appropriate ffprobe input flags without allowed_extensions on non-HLS.
     */
    protected function addProbeInputFlags(array &$args, Channel $channel): void
    {
        $type = $channel->source_type;
        $url = $channel->source_url;

        if ($this->isHttpUrl($url)) {
            $ua = $this->httpUserAgent();
            if ($ua) {
                array_push($args, '-user_agent', $ua);
            }
        }

        if ($type === 'hls' && ! $this->isHttpMpegts($url, $type)) {
            array_push($args, '-allowed_extensions', 'ALL');

            if (str_starts_with(strtolower($url), 'https://')
                && config('skymedia.hls_tls_verify', false) === false) {
                array_push($args, '-tls_verify', '0');
            }
        }

        if (in_array($type, ['udp', 'mpegts', 'srt'])) {
            array_push($args, '-analyzeduration', '2000000', '-probesize', '3000000');
        }
    }

    // ===================================================================
    //  INTERNAL — ENCODING FLAGS
    // ===================================================================

    protected function videoEncodeFlags(Channel $channel): array
    {
        $codec = $channel->push_video_codec ?? 'copy';
        if ($codec === 'copy') {
            return ['-c:v', 'copy'];
        }

        $ffCodec = match ($codec) {
            'h264' => 'libx264', 'h265' => 'libx265',
            'vp8' => 'libvpx',  'vp9' => 'libvpx-vp9',
            default => 'libx264',
        };

        $flags = ['-c:v', $ffCodec];

        if ($channel->push_video_bitrate) {
            $kbps = (int) $channel->push_video_bitrate;
            $flags = array_merge($flags, [
                '-b:v', "{$kbps}k",
                '-maxrate', (int) ($kbps * 1.2) . 'k',
                '-bufsize', ($kbps * 2) . 'k',
            ]);
        }

        if (! empty($channel->push_resolution)) {
            $flags = array_merge($flags, ['-vf', "scale={$channel->push_resolution}"]);
        }

        if ($channel->push_framerate) {
            $flags = array_merge($flags, ['-r', (string) $channel->push_framerate]);
        }

        if (in_array($codec, ['h264', 'h265'])) {
            $fps = $channel->push_framerate ?? 25;
            $flags = array_merge($flags, [
                '-preset', 'veryfast', '-tune', 'zerolatency',
                '-g', (string) ($fps * 2), '-keyint_min', (string) $fps,
                '-sc_threshold', '0',
            ]);
        }

        return $flags;
    }

    protected function audioEncodeFlags(Channel $channel): array
    {
        $codec = $channel->push_audio_codec ?? 'aac';
        if ($codec === 'copy') {
            return ['-c:a', 'copy'];
        }

        $ffCodec = match ($codec) {
            'aac' => 'aac', 'mp3' => 'libmp3lame',
            'opus' => 'libopus', 'ac3' => 'ac3',
            default => 'aac',
        };

        return [
            '-c:a', $ffCodec,
            '-b:a', ((int) ($channel->push_audio_bitrate ?? 128)) . 'k',
            '-ar',  (string) (int) ($channel->push_audio_samplerate ?? 48000),
            '-ac',  (string) (int) ($channel->push_audio_channels ?? 2),
        ];
    }

    // ===================================================================
    //  INTERNAL — PUSH URL
    // ===================================================================

    protected function hlsOutputFlags(Channel $channel, ?PushDestination $destination = null): array
    {
        $baseUrl = rtrim($destination?->url ?? $channel->push_url ?? '', '/');
        $segDuration = max(1, (int) ($channel->push_hls_segment_duration ?? $channel->segment_duration ?? 4));
        $listSize = (int) ($channel->push_hls_list_size ?? 10);

        // Segments live next to the playlist. If a stream_key is provided, treat it as
        // a sub-directory / path prefix so multiple channels can share one base URL.
        $prefix = ($destination?->stream_key ?? $channel->push_stream_key) ? trim($destination?->stream_key ?? $channel->push_stream_key, '/') . '/' : '';
        $segPattern = "{$baseUrl}/{$prefix}seg_%05d.ts";

        $flags = [
            '-f',                    'hls',
            '-hls_time',             (string) $segDuration,
            '-hls_list_size',        (string) $listSize,
            '-hls_flags',            'delete_segments+omit_endlist+append_list',
            '-hls_delete_threshold', '2',
            '-hls_segment_type',     'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache',      '0',
            // LLOD v3 — force keyframes every 2 seconds for instant
            // playback and clean segment splits on the player side.
            '-force_key_frames',     'expr:gte(t,n_forced*2)',
            // LLOD v3 — skip B-frames to reduce decoder latency.
            '-bf',                   '0',
        ];

        // If the target is an HTTP(S) endpoint, tell ffmpeg to PUT the files.
        if (str_starts_with($baseUrl, 'http://') || str_starts_with($baseUrl, 'https://')) {
            $flags[] = '-method';
            $flags[] = 'PUT';
        }

        return $flags;
    }

    protected function buildDestinationPushUrl(PushDestination $dest): string
    {
        $baseUrl = rtrim($dest->url, '/');
        $prefix = $dest->stream_key ? trim($dest->stream_key, '/') . '/' : '';

        if ($dest->protocol === 'hls') {
            if (str_starts_with($baseUrl, 'http://') || str_starts_with($baseUrl, 'https://')) {
                return "{$baseUrl}/{$prefix}index.m3u8";
            }
            $dir = "{$baseUrl}/{$prefix}";
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return "{$dir}index.m3u8";
        }

        if ($dest->protocol === 'srt') {
            $latency = $this->srtLatencyMs() * 1000;
            $base = preg_replace('#^srt://#', '', $baseUrl);
            $query = "latency={$latency}&mode=caller";
            if ($dest->username) {
                $query .= '&username=' . urlencode($dest->username);
            }
            if ($dest->password) {
                $query .= '&passphrase=' . urlencode($dest->password);
            }

            return "srt://{$base}/{$dest->stream_key}?{$query}";
        }

        // RTMP
        $target = "{$baseUrl}/{$dest->stream_key}";
        if ($dest->username || $dest->password) {
            $user = urlencode($dest->username ?? '');
            $pass = urlencode($dest->password ?? '');
            $target = preg_replace('#^(rtmps?://)#', "$1{$user}:{$pass}@", $target);
        }

        return $target;
    }

    protected function pushUrl(Channel $channel): string
    {
        if ($channel->push_protocol === 'hls') {
            $baseUrl = rtrim($channel->push_url ?? '', '/');
            $prefix = $channel->push_stream_key ? trim($channel->push_stream_key, '/') . '/' : '';

            if (str_starts_with($baseUrl, 'http://') || str_starts_with($baseUrl, 'https://')) {
                return "{$baseUrl}/{$prefix}index.m3u8";
            }

            // Local/network path: ensure directory exists.
            $dir = "{$baseUrl}/{$prefix}";
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return "{$dir}index.m3u8";
        }

        if ($channel->push_protocol === 'srt') {
            $latency = $this->srtLatencyMs() * 1000;
            $base = $this->parseSrtUrl($channel->push_target);
            $query = "latency={$latency}&mode=caller";
            if ($channel->push_username) {
                $query .= '&username=' . urlencode($channel->push_username);
            }
            if ($channel->push_password) {
                $query .= '&passphrase=' . urlencode($channel->push_password);
            }

            return "srt://{$base}?{$query}";
        }

        // RTMP — credentials embedded as rtmp://user:pass@host/app/key
        $target = $channel->push_target;
        if ($channel->push_username || $channel->push_password) {
            $user = urlencode($channel->push_username ?? '');
            $pass = urlencode($channel->push_password ?? '');
            // Insert credentials after rtmp(s)://
            $target = preg_replace(
                '#^(rtmps?://)#',
                "$1{$user}:{$pass}@",
                $target
            );
        }

        return $target;
    }

    protected function parseSrtUrl(string $url): string
    {
        return preg_replace('#^srt://#', '', $url);
    }
}
