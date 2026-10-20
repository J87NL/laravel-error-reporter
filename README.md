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
|                                   | `scrub`                      | see below             |
|                                   | `ignore` (exception classes) | `[]`                  |
|                                   | `transport`                  | `QueuedHttpTransport` |

## What it sends

The package hooks into the exception handler. Every reported exception is still logged as usual; the package also queues one job that posts these fields to the collector:

- fingerprint, exception class, message, file and line;
- a trimmed stack trace, each frame marked in-app or vendor;
- route, method, URL without the query string, user id, environment and release;
- the exception's `context()`.

A `QueryException` is sent with its SQLSTATE code and its SQL, without the bindings and without the driver's message, which can contain the bound values.

The job uses the configured timeout and does not retry. When the collector is down or refuses the report, the report goes to the log instead. A failure inside the job itself, and a delivery job that times out or runs out of attempts, is never reported again.

The job is queued even when it is reported inside a database transaction that is rolled back. With the `sync` queue the request waits for the collector, at most the timeout; use a real queue in production.

Exceptions that the handler does not report never reach the package: exceptions in the handler's `dontReport` list, and exceptions with their own `report()` method that handles the reporting.

## Scrubbing

Before anything leaves the app, every key containing `password`, `token`, `secret`, `authorization`, `cookie` or `key` is replaced by `[scrubbed]`, at any depth. The match is a substring match, so `key` also masks keys like `monkey` and `primary_key`. Change the list in `error-reporter.scrub`.

Route parameters whose name matches the same list are masked in the reported URL.

## Browser errors

`resources/js/errorReporter.ts` hooks `app.config.errorHandler`, `error` and `unhandledrejection`. It posts with the public browser key, at most ten distinct errors per page load. Path segments of 32 or more letters, digits, `-` and `_`, such as reset tokens, are masked in the reported URL. A rejection with something other than an `Error` is sent as its `message` property, or else only as its type, so objects are never sent whole. Installing it twice on the same app does nothing.

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

## Other transports

Bind your own `J87NL\ErrorReporter\Contracts\ErrorTransport` through `error-reporter.transport`, for example to send reports somewhere other than the collector.

## Testing

```bash
composer install
vendor/bin/pest
```
