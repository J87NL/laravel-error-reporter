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
| `ignore` | | `[]` |

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
case and matches parts of words. See [Scrubbing & Privacy](scrubbing.md).

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
