# Laravel Error Reporter

A Laravel package that sends the errors of your application to the J87 error collector.

It reports two kinds of errors: exceptions that Laravel's exception handler reports on the server,
and errors that happen in the browser of a Vue app. The collector groups them, so the same error
shows up once with a count instead of once per occurrence.

## How it works

1. The package hooks into Laravel's exception handler. Every exception the handler reports is still
   logged as usual.
2. The package turns the exception into a report: class, message, file, line, a trimmed stack trace,
   route, URL, user id, environment, release and the exception's context. Secrets are masked first.
3. A queued job posts the report to the collector with your server key. When the collector cannot be
   reached, the report goes to the log instead.
4. In the browser, a small script catches Vue errors, uncaught errors and unhandled promise
   rejections, and posts them with a public browser key.
5. Optionally, the package pings a heartbeat every five minutes from the scheduler and from each
   watched queue, so the collector notices when either stops.

## Quick example

**`.env`:**

```dotenv
ERROR_REPORTER_ENDPOINT=https://j87.nl/api/ingest/errors
ERROR_REPORTER_KEY=srv_...
ERROR_REPORTER_BROWSER_KEY=pub_...
APP_RELEASE=
```

That is all the server side needs. From now on every reported exception is queued for the collector.

**Browser errors, in `HandleInertiaRequests`:**

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'errorReporter' => fn (): ?array => app(ErrorReporter::class)->browserConfig(),
    ];
}
```

**And in `resources/js/app.ts`:**

```ts
import { createInertiaApp } from '@inertiajs/vue3';
import { installErrorReporter } from '@error-reporter/errorReporter';

createInertiaApp({
    // ...
    withApp: (app, { page }) => {
        installErrorReporter(app, page.props.errorReporter);
    },
});
```

The Vite alias and the other steps are in [Vue Usage](browser-errors.md).

## Next steps

- [Installation](installation.md) - set up the package
- [How Reporting Works](server-errors.md) - what is reported, and when
- [The Report](payload.md) - every field that leaves your app
- [Scrubbing & Privacy](scrubbing.md) - what is masked before sending
- [Vue Usage](browser-errors.md) - report browser errors
- [Heartbeats](heartbeats.md) - let the collector watch your scheduler and queues
- [Configuration](configuration.md) - all config keys
- [Transports](transports.md) - send reports your own way
- [Collector API](collector-api.md) - what the collector accepts and answers
- [Testing](testing.md) - keep tests quiet, or assert reports
