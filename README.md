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

`APP_RELEASE` is the git SHA of the deployed release.

## What it sends

The package hooks into the exception handler. Every reported exception is still logged as usual; the package also queues one job that posts these fields to the collector:

- fingerprint, exception class, message, file and line;
- a trimmed stack trace (`error-reporter.max_frames`, 30 by default), each frame marked in-app or vendor;
- route, method, URL without the query string, user id, environment and release;
- the exception's `context()`.

The job uses a 2-second timeout and does not retry. When the collector is down or refuses the report, the report goes to the log instead (`ERROR_REPORTER_LOG_CHANNEL`).

## Scrubbing

Before anything leaves the app, every key containing `password`, `token`, `secret`, `authorization`, `cookie` or `key` is replaced by `[scrubbed]`, at any depth. Change the list in `error-reporter.scrub`.

## Browser errors

`resources/js/errorReporter.ts` hooks `app.config.errorHandler`, `error` and `unhandledrejection`. It posts with the public browser key, at most ten distinct errors per page load.

Share `ErrorReporter::browserConfig()` as a page prop and install it:

```ts
import { installErrorReporter } from '@error-reporter/errorReporter';

installErrorReporter(app, page.props.errorReporter);
```

`browserConfig()` returns `null` while no endpoint or browser key is set, and the script then does nothing.

## Other transports

Bind your own `J87NL\ErrorReporter\Contracts\ErrorTransport` through `error-reporter.transport`. The collector itself uses this to send its own errors to ntfy instead of to its own ingest.

## Testing

```bash
composer install
vendor/bin/pest
```
