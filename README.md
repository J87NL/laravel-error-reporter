# j87nl/laravel-error-reporter

Reports Laravel and browser errors to the J87 error collector on j87.nl.

## Installation

```bash
composer require j87nl/laravel-error-reporter
```

Set the collector address and the keys the collector's admin shows once when you add the app:

```dotenv
ERROR_REPORTER_ENDPOINT=https://j87.nl/api/ingest/errors
ERROR_REPORTER_KEY=srv_...
ERROR_REPORTER_BROWSER_KEY=pub_...
APP_RELEASE=
```

`APP_RELEASE` is the git SHA of the deployed release. While the endpoint or the server key is empty, the default transport queues and sends nothing.

To change more than the environment variables, publish the config:

```bash
php artisan vendor:publish --tag=error-reporter-config
```

| Environment variable              | Config key                   | Default               |
| --------------------------------- | ---------------------------- | --------------------- |
| `ERROR_REPORTER_ENABLED`          | `enabled`                    | `true`                |
| `ERROR_REPORTER_ENDPOINT`         | `endpoint`                   | none                  |
| `ERROR_REPORTER_KEY`              | `key`                        | none                  |
| `ERROR_REPORTER_BROWSER_KEY`      | `browser_key`                | none                  |
| `ERROR_REPORTER_QUEUE_CONNECTION` | `queue.connection`           | the default           |
| `ERROR_REPORTER_QUEUE`            | `queue.name`                 | the default           |
| `ERROR_REPORTER_LOG_CHANNEL`      | `log_channel`                | the default           |
| `APP_RELEASE`                     | `release`                    | none                  |
| `APP_ENV`                         | `environment`                | `production`          |
|                                   | `timeout` (seconds)          | `2`                   |
|                                   | `max_frames`                 | `30`                  |
|                                   | `max_message_length`         | `2000`                |
|                                   | `max_context_bytes`          | `8192`                |
|                                   | `scrub`                      | see below             |
|                                   | `ignore` (exception classes) | `[]`                  |
|                                   | `transport`                  | `QueuedHttpTransport` |

## What it sends

The package hooks into the exception handler. Every reported exception is still logged as usual; the package also queues one job that posts these fields to the collector:

- fingerprint, exception class, message, file and line;
- a trimmed stack trace, each frame marked in-app or vendor;
- route, method, URL without the query string, user id, environment and release;
- the exception's `context()`, replaced by `{"truncated": true}` when its JSON is larger than `max_context_bytes`.

A `QueryException` is sent with its SQLSTATE code and its SQL, without the bindings and without the driver's message, which can contain the bound values.

The job uses the configured timeout and does not retry. When the collector is down or refuses the report, the report goes to the log instead. A failure inside the job itself, and a delivery job that times out or runs out of attempts, is never reported again.

The job is dispatched with `beforeCommit()`, so it never waits for an open database transaction. Whether it survives a rollback depends on the queue. With Redis, SQS or another queue outside the database, it does. With `QUEUE_CONNECTION=database` on the same database connection as the transaction, the job row is written inside that transaction and rolled back with it, so the report is lost. Point `ERROR_REPORTER_QUEUE_CONNECTION` at a queue outside the database, or at a `database` queue on a connection of its own, when that matters. With the `sync` queue the request waits for the collector, at most the timeout; use a real queue in production.

When the transport itself fails, for example because the queue cannot be reached, the package logs a warning with the scrubbed report, so the report is not lost.

Exceptions that the handler does not report never reach the package: exceptions in the handler's `dontReport` list, and exceptions with their own `report()` method that handles the reporting.

## Scrubbing

Before anything leaves the app, every key containing `password`, `token`, `secret`, `authorization`, `cookie` or `key` is replaced by `[scrubbed]`, at any depth. The match is a substring match, so `key` also masks keys like `monkey` and `primary_key`. Change the list in `error-reporter.scrub`.

Route parameters whose name matches the same list are masked in the reported URL.

## Browser errors

`resources/js/errorReporter.ts` hooks `app.config.errorHandler`, `error` and `unhandledrejection`. It posts with the public browser key, at most ten distinct errors per page load. Path segments of 32 or more letters, digits, `-` and `_`, such as reset tokens, are masked in the reported URL. A rejection with something other than an `Error` is sent as its `message` property, a number or boolean as its value, and anything else only as its type, so objects are never sent whole. Installing it twice on the same app does nothing.

Point an alias at the script in `vite.config.ts`:

```ts
resolve: {
    alias: {
        '@error-reporter': fileURLToPath(
            new URL(
                './vendor/j87nl/laravel-error-reporter/resources/js',
                import.meta.url,
            ),
        ),
    },
},
```

and in `tsconfig.json`:

```json
"paths": {
    "@error-reporter/*": [
        "./vendor/j87nl/laravel-error-reporter/resources/js/*"
    ]
}
```

Share the browser config in `HandleInertiaRequests`:

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'errorReporter' => fn (): ?array => app(ErrorReporter::class)->browserConfig(),
    ];
}
```

and install the script when the app is created:

```ts
import { installErrorReporter } from '@error-reporter/errorReporter';

installErrorReporter(app, page.props.errorReporter);
```

`browserConfig()` returns `null` while no endpoint or browser key is set, and the script then does nothing.

When the app sends a Content-Security-Policy, add the collector's origin to `connect-src`, for example `connect-src 'self' https://j87.nl`. Without it the browser blocks the report.

## Heartbeats

Optionally, the package lets the collector watch your scheduler and queue workers. Every five minutes the scheduler pings its own heartbeat, and queues a small job per watched queue that pings that queue's heartbeat when a worker handles it. A ping that fails never throws; it logs one warning per outage, without the token.

Create the heartbeats in the j87.nl admin under Monitoring → Heartbeats, with an interval of 5 minutes and a grace of 5 (scheduler) or 10 (queue) minutes, and add the keys and the tokens the admin shows once:

```dotenv
ERROR_REPORTER_HEARTBEATS_ENABLED=true
ERROR_REPORTER_SCHEDULER_HEARTBEAT_KEY=shop-scheduler
ERROR_REPORTER_SCHEDULER_HEARTBEAT_TOKEN=hb_...
ERROR_REPORTER_QUEUE_HEARTBEAT_KEY=shop-queue
ERROR_REPORTER_QUEUE_HEARTBEAT_TOKEN=hb_...
```

| Environment variable                         | Config key                       | Default                        |
| -------------------------------------------- | -------------------------------- | ------------------------------ |
| `ERROR_REPORTER_HEARTBEATS_ENABLED`          | `heartbeats.enabled`             | `false`                        |
| `ERROR_REPORTER_HEARTBEAT_ENDPOINT`          | `heartbeats.endpoint`            | derived from the error endpoint |
| `ERROR_REPORTER_SCHEDULER_HEARTBEAT_KEY`     | `heartbeats.scheduler.key`       | none                           |
| `ERROR_REPORTER_SCHEDULER_HEARTBEAT_TOKEN`   | `heartbeats.scheduler.token`     | none                           |
| `ERROR_REPORTER_QUEUE_HEARTBEAT_KEY`         | `heartbeats.queues.default.key`  | none                           |
| `ERROR_REPORTER_QUEUE_HEARTBEAT_TOKEN`       | `heartbeats.queues.default.token` | none                          |
| `ERROR_REPORTER_QUEUE_HEARTBEAT_CONNECTION`  | `heartbeats.queues.default.connection` | the default              |
| `ERROR_REPORTER_QUEUE_HEARTBEAT_QUEUE`       | `heartbeats.queues.default.queue` | the default                   |
| `ERROR_REPORTER_EXTRA_QUEUES`                | `heartbeats.extra_queues`        | none                           |

The address is derived from `ERROR_REPORTER_ENDPOINT` by swapping `/ingest/errors` for `/heartbeats`. Watch more queues by naming them in `ERROR_REPORTER_EXTRA_QUEUES`, with a key and token per name in upper snake case. The queue defaults to the name:

```dotenv
ERROR_REPORTER_EXTRA_QUEUES=mailing
ERROR_REPORTER_MAILING_HEARTBEAT_KEY=shop-mailing-queue
ERROR_REPORTER_MAILING_HEARTBEAT_TOKEN=hb_...
```

Queues under `heartbeats.queues` in a published config still work and win on a name clash. See [docs/heartbeats.md](docs/heartbeats.md).

## Other transports

Bind your own `J87NL\ErrorReporter\Contracts\ErrorTransport` through `error-reporter.transport`, for example to send reports somewhere other than the collector.

## Testing

```bash
composer install
vendor/bin/pest
```
