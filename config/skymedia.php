<?php

return [
    'ffmpeg_binary'  => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'dvr_base_path'  => env('DVR_BASE_PATH', storage_path('app/dvr')),
    'log_base_path'  => env('LOG_BASE_PATH', storage_path('logs/streams')),

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
];
