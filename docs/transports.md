# Transports

A transport decides how a report leaves your app. The package resolves it from the container as
`J87NL\ErrorReporter\Contracts\ErrorTransport`:

```php
<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Contracts;

interface ErrorTransport
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function send(array $report): void;
}
```

`$report` is the array described in [The Report](payload.md). Secrets are already masked.

## The default: QueuedHttpTransport

`J87NL\ErrorReporter\Transports\QueuedHttpTransport` dispatches a
`J87NL\ErrorReporter\Jobs\SendErrorReport` job:

- on the configured [queue connection and queue](configuration.md#queue);
- with `beforeCommit()`, so it does not wait for an open transaction. A `database` queue on the
  same connection still loses the job when that transaction rolls back, see
  [Transactions](server-errors.md#transactions).

While the endpoint or the server key is empty, it does nothing. The job itself is described in
[Delivery and fallback](server-errors.md#delivery-and-fallback).

## Your own transport

Write a class that implements `ErrorTransport`, for example to send reports somewhere other than the
collector:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use J87NL\ErrorReporter\Contracts\ErrorTransport;

class LogErrorTransport implements ErrorTransport
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function send(array $report): void
    {
        Log::channel('error-reports')->error($report['message'], $report);
    }
}
```

Set it in the published config:

```php
// config/error-reporter.php
'transport' => \App\Support\LogErrorTransport::class,
```

The class is resolved from the container, so its constructor can ask for dependencies. A class that
does not implement `ErrorTransport` is ignored and `QueuedHttpTransport` is used instead. A class
that does not exist throws while it is resolved: the package logs a warning and sends nothing, see
[Never breaking the host](server-errors.md#never-breaking-the-host).

You can also bind the contract yourself, for example in a service provider's `register()` method.
This replaces the config setting:

```php
use App\Support\LogErrorTransport;
use J87NL\ErrorReporter\Contracts\ErrorTransport;

$this->app->bind(ErrorTransport::class, LogErrorTransport::class);
```

## Things to keep in mind

- `send()` runs inside the exception handler, during the request or job that failed. For a
  [failed job](server-errors.md#failed-jobs) it runs inside the worker, just before a timed-out
  worker kills its process. Keep it fast; queue anything slow.
- Your transport receives every report, also when no endpoint or key is configured. Only the default
  transport checks those.
- When `send()` throws, the exception is not passed on. The package logs a warning instead, see
  [Never breaking the host](server-errors.md#never-breaking-the-host).
- The browser script does not use a transport. It always posts to the endpoint in its options.
