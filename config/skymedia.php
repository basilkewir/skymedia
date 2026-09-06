<?php

declare(strict_types=1);

return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'dvr_base_path' => env('DVR_BASE_PATH', storage_path('app/dvr')),
    'log_base_path' => env('LOG_BASE_PATH', storage_path('logs/streams')),
    'server_ip' => env('SKYMEDIA_SERVER_IP', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

    /*
     * How many seconds between each monitor loop tick.
     * Per-channel check_interval overrides this for health probes.
     */
    'monitor_tick' => env('SKYMEDIA_MONITOR_TICK', 3),
    'mediamtx_api' => env('MEDIAMTX_API_URL', 'http://rtmp:9997'),

    /*
     * Max DVR bitrate assumption (bits/sec) used for storage warnings.
     * Default: 10 Mbps
     */
    'dvr_max_bitrate' => env('SKYMEDIA_DVR_MAX_BITRATE', 10_000_000),

    /*
     * SRT latency in ms
     */
    'srt_latency' => env('SKYMEDIA_SRT_LATENCY', 200),

    /*
     * Minimum free disk space (bytes) that must remain available on the DVR
     * filesystem. Recording will not start (and will be stopped if already
     * running) when free space drops below this value.
     * Default: 5 GB
     */
    'min_free_disk_bytes' => env('SKYMEDIA_MIN_FREE_DISK_BYTES', 5 * 1024 * 1024 * 1024),

    /*
     * Alert webhook URL — receives JSON POST on channel state changes.
     * Leave empty to disable webhook alerts.
     */
    'alert_webhook_url' => env('SKYMEDIA_ALERT_WEBHOOK_URL', ''),

    /*
     * User-Agent sent by FFmpeg/FFprobe for HTTP(S) based sources.
     * Many HLS/IPTV endpoints block the default FFmpeg User-Agent or require
     * a browser-like UA. Leave empty to let FFmpeg use its default.
     */
    'http_user_agent' => env('SKYMEDIA_HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),

    /*
     * Disable TLS certificate verification for HTTP(S) HLS sources.
     * Many IPTV/HLS endpoints use self-signed, expired, or otherwise
     * non-standard certificates that FFmpeg refuses to validate. Set to true
     * to enforce verification (more secure, but will reject those streams).
     */
    'hls_tls_verify' => env('SKYMEDIA_HLS_TLS_VERIFY', false),

    /*
     * Minimum hours to keep completed recording files, regardless of the
     * channel's keep_recordings setting. Prevents recordings from being
     * deleted too aggressively before they can be used as fallback VOD.
     * Default: 24 hours
     */
    'min_recording_retention_hours' => env('SKYMEDIA_MIN_RECORDING_RETENTION_HOURS', 24),

    /*
     * LLOD v3 — Low-Latency On-Demand fallback mode.
     * When true, fallback content is always re-encoded to H.264 with a
     * fixed 2-second GOP and no B-frames. This guarantees instant playback
     * startup and clean segment splits, at the cost of additional CPU.
     * When false, fallback uses stream-copy when possible (lower CPU but
     * segment quality depends on the source files' keyframe cadence).
     * Default: false
     */
    'llod_v3_reencode_fallback' => env('SKYMEDIA_LLOD_V3_REENCODE_FALLBACK', false),

    /*
     * LLOD v3 — Low-Latency On-Demand ingest mode.
     * When true, incoming streams are re-encoded to H.264 with a fixed
     * 2-second GOP and AAC audio. This guarantees short HLS segments and
     * instant playback on downstream players/panels, at the cost of
     * additional CPU. Use for sources with long GOPs (e.g. > 5 seconds).
     * Default: false
     */
    'llod_v3_reencode_ingest' => env('SKYMEDIA_LLOD_V3_REENCODE_INGEST', false),

    /*
     * Low-latency tuning ─────────────────────────────────────────────────
     *
     * ingest_hls_list_size: Number of segments in the ingest HLS playlist.
     *   Lower = faster failover detection + lower push latency.
     *   3 segments = 6s buffer (fastest), 5 = 10s (balanced), 10+ = stable.
     *
     * push_probe_size: FFmpeg probe size for push input (bytes).
     *   Lower = faster push startup. 500000 (0.5MB) is good for known-good HLS.
     *
     * push_analyze_duration: FFmpeg analyze duration for push input (microseconds).
     *   Lower = faster push startup. 500000 (0.5s) is good for known-good HLS.
     *
     * push_max_reload: Max HLS playlist reloads without new segments before push gives up.
     *   Lower = faster detection of dead source. 100 = ~100s at 1s reload interval.
     *
     * segment_freshness_seconds: How old a segment can be before ingest is considered stale.
     *   Lower = faster failover. 10s is good for production (prevents false positives).
     */
    'ingest_hls_list_size' => env('SKYMEDIA_INGEST_HLS_LIST_SIZE', 3),
    'ingest_probe_size' => env('SKYMEDIA_INGEST_PROBE_SIZE', 5000000),
    'ingest_analyze_duration' => env('SKYMEDIA_INGEST_ANALYZE_DURATION', 3000000),
    'push_probe_size' => env('SKYMEDIA_PUSH_PROBE_SIZE', 500000),
    'push_analyze_duration' => env('SKYMEDIA_PUSH_ANALYZE_DURATION', 500000),
    'push_max_reload' => env('SKYMEDIA_PUSH_MAX_RELOAD', 100),
    'segment_freshness_seconds' => env('SKYMEDIA_SEGMENT_FRESHNESS_SECONDS', 10),

    /*
     * YouTube Data API v3 key — required for adding YouTube videos to
     * TV playout playlists. Used to fetch video metadata (title, duration)
     * without hitting bot detection.
     * Get a key at: https://console.cloud.google.com/apis/credentials
     */
    'youtube_api_key' => env('YOUTUBE_API_KEY', ''),
];
