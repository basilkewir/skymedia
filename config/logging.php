<?php

return [
    /*
     * Logging channels for SkyMedia stream operations.
     * Each stream component gets its own log file for easy debugging.
     */
    'channels' => [
        'ingest' => [
            'driver'  => 'daily',
            'path'    => storage_path('logs/streams/ingest.log'),
            'level'   => env('LOG_LEVEL', 'info'),
            'days'    => 30,
        ],
        'push'   => [
            'driver'  => 'daily',
            'path'    => storage_path('logs/streams/push.log'),
            'level'   => env('LOG_LEVEL', 'info'),
            'days'    => 30,
        ],
        'monitor' => [
            'driver'  => 'daily',
            'path'    => storage_path('logs/streams/monitor.log'),
            'level'   => env('LOG_LEVEL', 'info'),
            'days'    => 30,
        ],
        'recording' => [
            'driver'  => 'daily',
            'path'    => storage_path('logs/streams/recording.log'),
            'level'   => env('LOG_LEVEL', 'info'),
            'days'    => 30,
        ],
    ],

    /*
     * Audit trail — logs all critical state transitions for compliance.
     */
    'audit' => [
        'enabled' => env('SKYMEDIA_AUDIT_LOG', false),
        'path'    => storage_path('logs/audit.log'),
    ],
];
