<?php

declare(strict_types=1);

return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'dvr_base_path' => env('DVR_BASE_PATH', storage_path('app/dvr')),
    'log_base_path' => env('LOG_BASE_PATH', storage_path('logs/streams')),

    /*
     * How many seconds between each monitor loop tick.
     * Per-channel check_interval overrides this for health probes.
     */
    'monitor_tick' => env('SKYMEDIA_MONITOR_TICK', 3),

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
];
