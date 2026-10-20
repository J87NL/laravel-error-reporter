# How Reporting Works

The package registers a `reportable` callback on Laravel's exception handler. Every exception the
handler reports passes through `J87NL\ErrorReporter\ErrorReporter::report()`. Laravel still logs the
exception as usual; the collector receives a copy.

The hook is only added when the application's exception handler extends
`Illuminate\Foundation\Exceptions\Handler`, as the handler of every standard Laravel application does.
With a handler that only implements the `ExceptionHandler` contract, nothing is reported.

## The steps

1. The handler reports an exception.
2. `ErrorReporter::report()` checks whether it should be sent: the package is
   [enabled](configuration.md#enabled), the class is not in [`ignore`](configuration.md#ignore), and
   the exception is not a failure of the package's own delivery job.
3. `ErrorReport::fromThrowable()` builds the report from the exception, the current request and the
   id of the logged-in user, and masks secrets. See [The Report](payload.md).
4. The [transport](transports.md) sends the report. The default, `QueuedHttpTransport`, dispatches a
   `SendErrorReport` job.
5. The job posts the report to the collector.

## What is not reported

Only exceptions the handler reports reach the package. These never do:

- exceptions Laravel does not report by default, such as validation errors, authentication and
  authorization errors, HTTP exceptions and `ModelNotFoundException`;
- exceptions in the handler's `dontReport` list;
- exceptions with their own `report()` method that handles the reporting.

These reach the package but are not sent:

- every exception while `enabled` is `false`;
- exception classes, and their subclasses, listed in `ignore`;
- failures of the `SendErrorReport` job itself, see [No loops](#no-loops).

## Reporting by hand

A caught exception passed to Laravel's `report()` helper goes through the handler, so it is sent as well:

```php
try {
    $gateway->charge($order);
} catch (PaymentFailed $exception) {
    report($exception);

    return back()->with('error', __('The payment failed.'));
}
```

## Requests, users and the console

The report holds the route name, method and URL of the current request, and the id of the user on
the default guard. For an exception in a queued job or an artisan command there is no route, so
`route`, `method` and `url` are `null`. When no user is logged in, `user_id` is `null`.

## Delivery and fallback

`SendErrorReport` posts the report as JSON to the [endpoint](configuration.md#endpoint-and-keys), with
the server key as a bearer token:

- The connect timeout and the request timeout are both the [timeout](configuration.md#timeout),
  two seconds by default.
- The job runs once (`$tries = 1`) and does not retry.
- When the collector cannot be reached, or answers with a 4xx or 5xx status, the job writes a warning
  to the [log channel](configuration.md#log-channel): "An error report could not be delivered to the
  collector.", with the reason and the full report.
- When the endpoint or key is empty by the time the job runs, the report goes to the log the same way.

## Transactions

The job is dispatched with `beforeCommit()`. It is queued right away, even when the exception is
reported inside a database transaction that is rolled back afterwards. Without this, the report of
the error that caused the rollback would be lost.

## No loops

The package never reports its own failures:

- An exception thrown from inside `SendErrorReport` is not sent again.
- A `SendErrorReport` job that times out or runs out of attempts is not sent again. The same
  exceptions from your own jobs are sent as usual.
- While a report is being built and handed to the transport, a second exception reported at the
  same moment is skipped.

## Never breaking the host

Building or sending a report never throws into your code. When it fails, for example because the
transport throws, the package logs a warning, "The error reporter failed to report an exception.",
with the exception class and the reason. This warning goes to the default log channel, not to
[`log_channel`](configuration.md#log-channel). When logging fails too, the failure is swallowed.
