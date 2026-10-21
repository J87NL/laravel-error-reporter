# Vue Usage

The package ships a TypeScript file, `resources/js/errorReporter.ts`, that reports errors from a Vue 3
app to the collector. It catches:

- errors in Vue components, through `app.config.errorHandler`;
- uncaught errors, through the window's `error` event;
- unhandled promise rejections, through the window's `unhandledrejection` event.

The script is shipped as source. Your own Vite build compiles it.

## 1. Add the Vite alias

In `vite.config.ts`:

```ts
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        // ...
    ],
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
});
```

## 2. Add the TypeScript path

In `tsconfig.json`:

```json
{
    "compilerOptions": {
        "paths": {
            "@error-reporter/*": [
                "./vendor/j87nl/laravel-error-reporter/resources/js/*"
            ]
        }
    }
}
```

## 3. Share the browser config

`ErrorReporter::browserConfig()` returns what the script needs, or `null` while the package is
disabled or the endpoint or browser key is empty. Share it in `HandleInertiaRequests`:

```php
use J87NL\ErrorReporter\ErrorReporter;

public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'errorReporter' => fn (): ?array => app(ErrorReporter::class)->browserConfig(),
    ];
}
```

The shared value looks like this:

```json
{
    "endpoint": "https://j87.nl/api/ingest/errors",
    "key": "pub_...",
    "release": "abc1234",
    "environment": "production"
}
```

The server key is never part of it.

## 4. Install the script

Call `installErrorReporter()` when the app is created, for example in `resources/js/app.ts`:

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

`withApp` is used when you let Inertia mount the app. When your app has its own `setup()` callback,
call `installErrorReporter(app, props.initialPage.props.errorReporter)` there, after creating the app.

When the options are `null` or `undefined`, or the endpoint or key is not a string or is empty, the
call does nothing. Calling it twice for the same app does nothing either.

### Content-Security-Policy

When your app sends a Content-Security-Policy, add the collector's origin to `connect-src`, for
example `connect-src 'self' https://j87.nl`. Without it the browser blocks the report.

### Typing the shared prop

The file exports the options type, so you can type the shared prop:

```ts
import type { ErrorReporterOptions } from '@error-reporter/errorReporter';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            errorReporter: ErrorReporterOptions | null;
        };
    }
}
```

## Options

```ts
type ErrorReporterOptions = {
    endpoint: string;
    key: string;
    release?: string | null;
    environment?: string | null;
    maxReports?: number;
};
```

| Option | Description |
|---|---|
| `endpoint` | The collector's ingest address |
| `key` | The public browser key |
| `release` | Sent with every error |
| `environment` | Sent with every error |
| `maxReports` | The number of distinct errors sent per page load. Default `10` |

`browserConfig()` does not return `maxReports`. To change it, add it yourself:

```ts
const config = page.props.errorReporter;

installErrorReporter(app, config ? { ...config, maxReports: 5 } : null);
```

## What is sent

Each error is posted once per page load. Two errors count as the same when their name and message
are equal. After `maxReports` distinct errors, nothing more is sent until the page reloads.

The body is JSON:

| Field | Holds |
|---|---|
| `key` | The browser key |
| `exception_class` | The error's `name`, or `Error` |
| `message` | The message, cut to 2000 characters |
| `stack` | The error's stack, cut to 8000 characters, or `null` |
| `url` | The page address without query string or hash, with token-like segments masked |
| `release` | The release, or `null` |
| `environment` | The environment, or `null` |
| `context` | `{ "source": "vue" }`, `{ "source": "window" }` or `{ "source": "promise" }` |

Some details:

- A value that is not an `Error` is sent with the class `Error`. A string, number or boolean is sent as
  its value. An object with a string `message` property is sent as that message; any other value only
  as its type, such as `[object Object]`. Objects are never sent whole.
- Messages `Script error.` and `Script error` are ignored. Browsers report errors from scripts on
  other origins this way, without any details.
- Every path segment of 32 or more letters, digits, `-` and `_` is replaced by `[scrubbed]`, so a
  URL such as `/reset-password/<token>` does not leak the token.

## How it is sent

The script uses `fetch()` with:

- `Content-Type: text/plain` and `mode: 'no-cors'`, so the browser sends no CORS preflight request;
- `credentials: 'omit'`, so no cookies are sent;
- `keepalive: true`, so a report still leaves when the page is being closed.

The response cannot be read and failures are ignored. Reporting an error never throws.

## Existing error handlers

When the app already has an `app.config.errorHandler`, the script calls it after reporting. Without
one, it logs the error with `console.error()`, as Vue does by default. Set your own handler before
calling `installErrorReporter()`.
