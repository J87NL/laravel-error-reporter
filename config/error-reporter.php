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

];
