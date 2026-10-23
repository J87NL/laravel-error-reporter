<?php

declare(strict_types=1);

use J87NL\ErrorReporter\Heartbeats\EnvironmentQueues;
use J87NL\ErrorReporter\RequestBody;
use J87NL\ErrorReporter\Transports\QueuedHttpTransport;
use J87NL\ErrorReporter\ValueRedactor;

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

    'ip' => env('ERROR_REPORTER_IP', 'masked'),

    'include_request_body' => (bool) env('ERROR_REPORTER_INCLUDE_REQUEST_BODY', true),

    'max_request_body_bytes' => 16384,

    'request_body_exclude' => RequestBody::DEFAULT_EXCLUDE,

    'scrub' => [
        'password',
        'token',
        'secret',
        'authorization',
        'cookie',
        'key',
    ],

    'redact' => [
        'secrets' => (bool) env('ERROR_REPORTER_REDACT_SECRETS', true),

        'personal_data' => (bool) env('ERROR_REPORTER_REDACT_PERSONAL_DATA', true),

        'query_parameters' => ValueRedactor::DEFAULT_QUERY_PARAMETERS,

        'patterns' => [],
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

        'extra_queues' => EnvironmentQueues::parse(env('ERROR_REPORTER_EXTRA_QUEUES')),
    ],

];
