<?php

declare(strict_types=1);

use J87NL\ErrorReporter\Transports\QueuedHttpTransport;

return [

    'enabled' => (bool) env('ERROR_REPORTER_ENABLED', true),

    'endpoint' => env('ERROR_REPORTER_ENDPOINT'),

    'key' => env('ERROR_REPORTER_KEY'),

    'browser_key' => env('ERROR_REPORTER_BROWSER_KEY'),

    'timeout' => 2,

    'queue' => [
        'connection' => env('ERROR_REPORTER_QUEUE_CONNECTION'),
        'name' => env('ERROR_REPORTER_QUEUE'),
    ],

    'release' => env('APP_RELEASE'),

    'environment' => env('APP_ENV', 'production'),

    'log_channel' => env('ERROR_REPORTER_LOG_CHANNEL'),

    'transport' => QueuedHttpTransport::class,

    'max_frames' => 30,

    'max_message_length' => 2000,

    'max_context_bytes' => 8192,

    'scrub' => [
        'password',
        'token',
        'secret',
        'authorization',
        'cookie',
        'key',
    ],

    'ignore' => [],

    'heartbeats' => [
        'enabled' => (bool) env('ERROR_REPORTER_HEARTBEATS_ENABLED', false),

        'endpoint' => env('ERROR_REPORTER_HEARTBEAT_ENDPOINT'),

        'timeout' => 3,

        'scheduler' => [
            'key' => env('ERROR_REPORTER_SCHEDULER_HEARTBEAT_KEY'),
            'token' => env('ERROR_REPORTER_SCHEDULER_HEARTBEAT_TOKEN'),
        ],

        'queues' => [
            'default' => [
                'key' => env('ERROR_REPORTER_QUEUE_HEARTBEAT_KEY'),
                'token' => env('ERROR_REPORTER_QUEUE_HEARTBEAT_TOKEN'),
                'connection' => env('ERROR_REPORTER_QUEUE_HEARTBEAT_CONNECTION'),
                'queue' => env('ERROR_REPORTER_QUEUE_HEARTBEAT_QUEUE'),
            ],
        ],
    ],

];
