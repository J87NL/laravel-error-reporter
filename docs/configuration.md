# Configuration

The package works with environment variables alone. To change anything else, publish the config:

```bash
php artisan vendor:publish --tag=error-reporter-config
```

This creates `config/error-reporter.php`.

## Overview

| Key | Environment variable | Default |
|---|---|---|
| `enabled` | `ERROR_REPORTER_ENABLED` | `true` |
| `endpoint` | `ERROR_REPORTER_ENDPOINT` | `null` |
| `key` | `ERROR_REPORTER_KEY` | `null` |
| `browser_key` | `ERROR_REPORTER_BROWSER_KEY` | `null` |
| `timeout` | | `2` |
| `queue.connection` | `ERROR_REPORTER_QUEUE_CONNECTION` | `null` (the default connection) |
| `queue.name` | `ERROR_REPORTER_QUEUE` | `null` (the default queue) |
| `release` | `APP_RELEASE` | `null` |
| `environment` | `APP_ENV` | `'production'` |
| `log_channel` | `ERROR_REPORTER_LOG_CHANNEL` | `null` (the default channel) |
| `transport` | | `QueuedHttpTransport::class` |
| `max_frames` | | `30` |
| `max_message_length` | | `2000` |
| `max_context_bytes` | | `8192` |
| `scrub` | | see [Scrub](#scrub) |
| `redact.secrets` | `ERROR_REPORTER_REDACT_SECRETS` | `true` |
| `redact.personal_data` | `ERROR_REPORTER_REDACT_PERSONAL_DATA` | `true` |
| `redact.query_parameters` | | see [Redact](#redact) |
| `redact.patterns` | | `[]` |
| `ignore` | | `[]` |
| `heartbeats.enabled` | `ERROR_REPORTER_HEARTBEATS_ENABLED` | `false` |
| `heartbeats.endpoint` | `ERROR_REPORTER_HEARTBEAT_ENDPOINT` | `null` (derived from `endpoint`) |
| `heartbeats.timeout` | | `3` |
| `heartbeats.scheduler` | `ERROR_REPORTER_SCHEDULER_HEARTBEAT_*` | see [Heartbeats](#heartbeats) |
| `heartbeats.queues` | `ERROR_REPORTER_QUEUE_HEARTBEAT_*` | see [Heartbeats](#heartbeats) |
| `heartbeats.extra_queues` | `ERROR_REPORTER_EXTRA_QUEUES` | `[]` |

---

## Enabled

```php
'enabled' => (bool) env('ERROR_REPORTER_ENABLED', true),
```

`false` turns off server reporting, and makes `browserConfig()` return `null` so the browser script
does nothing either.

---

## Endpoint and keys

```php
'endpoint' => env('ERROR_REPORTER_ENDPOINT'),

'key' => env('ERROR_REPORTER_KEY'),

'browser_key' => env('ERROR_REPORTER_BROWSER_KEY'),
```

| Key | Description |
|---|---|
| `endpoint` | The collector's ingest address, used by the server and the browser |
| `key` | The secret server key. The queued job sends it as `Authorization: Bearer ...` |
| `browser_key` | The public browser key. It is shared with the page, so it is not a secret |

While `endpoint` or `key` is empty, the default transport queues nothing. While `endpoint` or
`browser_key` is empty, `browserConfig()` returns `null`.

---

## Timeout

```php
'timeout' => 2,
```

The connect timeout and the request timeout of the HTTP call in the queued job, in seconds.

---

## Queue

```php
'queue' => [
    'connection' => env('ERROR_REPORTER_QUEUE_CONNECTION'),
    'name' => env('ERROR_REPORTER_QUEUE'),
],
```

The connection and queue the `SendErrorReport` job is dispatched on. An empty value uses your
application's default.

---

## Release and environment

```php
'release' => env('APP_RELEASE'),

'environment' => env('APP_ENV', 'production'),
```

Both are added to every server report and passed to the browser script. Set `APP_RELEASE` to the git
SHA of the deployed release in your deploy script. An empty release is sent as `null`.

---

## Log channel

```php
'log_channel' => env('ERROR_REPORTER_LOG_CHANNEL'),
```

The channel a report is written to when it cannot be delivered. `null` uses your default log channel.
See [Delivery and fallback](server-errors.md#delivery-and-fallback).

---

## Transport

```php
'transport' => QueuedHttpTransport::class,
```

The class that sends reports. It must implement `J87NL\ErrorReporter\Contracts\ErrorTransport`. A class
that does not is ignored and the default is used. A class that does not exist cannot be resolved:
nothing is sent, and every report logs the warning described in
[Never breaking the host](server-errors.md#never-breaking-the-host). See [Transports](transports.md).

---

## Limits

```php
'max_frames' => 30,

'max_message_length' => 2000,

'max_context_bytes' => 8192,
```

| Key | Description |
|---|---|
| `max_frames` | The number of stack frames sent, counting the frame where the exception was thrown. At least one is always sent |
| `max_message_length` | The length the exception message is cut to. A cut message ends with `...`, which comes on top of the limit |
| `max_context_bytes` | The largest scrubbed context, as JSON, that is sent. A larger context is sent as `{"truncated": true}` |

---

## Scrub

```php
'scrub' => [
    'password',
    'token',
    'secret',
    'authorization',
    'cookie',
    'key',
],
```

Context keys and route parameter names that contain one of these words are masked. The match ignores
case and matches parts of words. With [`redact.secrets`](#redact) on, parameters in the message, the
URL and the context with one of these words as a part of their name are masked as well. See
[Scrubbing & Privacy](scrubbing.md).

---

## Redact

```php
'redact' => [
    'secrets' => (bool) env('ERROR_REPORTER_REDACT_SECRETS', true),

    'personal_data' => (bool) env('ERROR_REPORTER_REDACT_PERSONAL_DATA', true),

    'query_parameters' => ValueRedactor::DEFAULT_QUERY_PARAMETERS,

    'patterns' => [],
],
```

| Key | Description |
|---|---|
| `secrets` | Masks `Bearer` and `Basic` credentials, secret parameters, passwords in URLs, `base64:` keys, JSON Web Tokens and opaque strings |
| `personal_data` | Masks e-mail addresses, IBANs and Dutch phone numbers |
| `query_parameters` | Words that mark a parameter as secret, on top of the [`scrub`](#scrub) words. Only used while `secrets` is on. The default is `code`, `auth`, `session`, `verifier`, `signature`, `apikey`, `sessionid`, `sessid` and `authcode` (`ValueRedactor::DEFAULT_QUERY_PARAMETERS`) |
| `patterns` | Your own regular expressions. Every match is masked, whatever `secrets` and `personal_data` say. An invalid pattern is skipped and logged once |

The rules apply to the message, the URL and every string key and value in the context, before the
message is cut and before the fingerprint is computed. A published config from before this section existed keeps
working: a missing `redact` section, or a missing key in it, falls back to the defaults above. See
[Values](scrubbing.md#values).

---

## Ignore

The default is an empty array. For example:

```php
'ignore' => [
    \App\Exceptions\PaymentDeclined::class,
],
```

Exception classes that are never sent to the collector. Subclasses are ignored too. These exceptions
are still logged by Laravel as usual; to stop Laravel reporting them at all, use the handler's
`dontReport` instead. See [What is not reported](server-errors.md#what-is-not-reported).

---

## Heartbeats

```php
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
```

| Key | Description |
|---|---|
| `enabled` | `true` schedules the heartbeats. Independent of `enabled` for error reporting |
| `endpoint` | The heartbeat address without the key. Empty swaps the trailing `/ingest/errors` of `endpoint` for `/heartbeats`. Without either, nothing is scheduled |
| `timeout` | The connect timeout and the request timeout of a ping, in seconds |
| `scheduler` | The key and token of the scheduler heartbeat |
| `queues` | One entry per watched queue, keyed by a name. Each has a key, a token and an optional connection and queue |
| `extra_queues` | The queues named in `ERROR_REPORTER_EXTRA_QUEUES`, read from `ERROR_REPORTER_{NAME}_HEARTBEAT_*`. On a name clash, `queues` wins |

A heartbeat without a key or a token is skipped. See [Heartbeats](heartbeats.md).
