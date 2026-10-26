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

Apart from [failed jobs](#failed-jobs), only exceptions the handler reports reach the package. These never do:

- exceptions Laravel does not report by default, such as validation errors, authentication and
  authorization errors, HTTP exceptions and `ModelNotFoundException`;
- exceptions in the handler's `dontReport` list;
- exceptions with their own `report()` method that handles the reporting.

These reach the package but are not sent:

- every exception while `enabled` is `false`;
- exception classes, and their subclasses, listed in `ignore`;
- failures of the `SendErrorReport` job itself, see [No loops](#no-loops).

## Failed jobs

A queued job that fails is reported too, also when the failure never passes the exception handler.
That matters most for timeouts: when a job runs past its `timeout`, the worker kills its own
process, so the handler never sees the `TimeoutExceededException`.

The package listens to two events of the queue worker, both fired before the worker kills the
process:

- `Illuminate\Queue\Events\JobFailed`, fired when a job is marked as failed: it threw on its last
  attempt, it timed out on its last attempt, it ran out of attempts or it called `$this->fail()`.
  The report holds the exception of the failure.
- `Illuminate\Queue\Events\JobTimedOut`, fired on every timeout. When the job has not failed yet,
  because it will be retried, the package reports a `TimeoutExceededException` for that attempt.
  When it has failed, the `JobFailed` report already covers it.

The report is built and handed to the [transport](transports.md) right there, inside the worker,
before the kill. With the default transport the delivery job is pushed to its queue before the
process stops.

Next to the usual fields, `context.job` holds:

| Key | Holds |
|---|---|
| `name` | The job's display name, such as `App\Jobs\ImportOrders` |
| `queue` | The queue the job ran on |
| `connection` | The queue connection |
| `attempts` | The number of attempts so far |

The job's payload and constructor arguments are never sent: they can hold personal data. The
[scrub words and value rules](scrubbing.md) apply to these fields as to any other context.

### Grouping

Every `TimeoutExceededException` and `MaxAttemptsExceededException` of one job lands in one group.
Their fingerprint uses the class and the exact message, which holds the job name, and not the stack
trace, which depends on where the job happened to be stopped. The message is not normalized, so
`ImportV2` and `ImportV3` stay apart. See [Fingerprint](payload.md#fingerprint).

### Sent once

An exception is sent at most once. A job that throws on its last attempt fires `JobFailed` first, and
the worker then passes the same exception to the handler. The package recognises the exception
object and does not send it again. A job that throws on an earlier attempt is reported by the
handler as before, without `context.job`. The same goes for an exception your job catches,
passes to `report()` and throws again: it is sent once, from `report()`, without `context.job`.

With `Worker::$killOnTimeout = false` (Laravel 13) the worker throws the timeout into the job
instead of killing the process. The package then skips `JobTimedOut`, and the timeout is sent once,
through `JobFailed` or the exception handler.

### Turning it off

`ignore`, the handler's `dontReport` list and `enabled` apply to failed jobs too. Set
`ERROR_REPORTER_REPORT_FAILED_JOBS=false` to stop reporting failed jobs; exceptions the handler
reports are still sent. See [Jobs](configuration.md#jobs).

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

The report holds the route name, method, URL, referrer, client address and input of the current
request, and the id of the user on the default guard. For an exception in a queued job or an artisan
command there is no route, so `route`, `method`, `url`, `referrer`, `ip` and `request_body` are
`null`. When no user is logged in, `user_id` is `null`.

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

The job is dispatched with `beforeCommit()`, so it is queued right away and never waits for an open
database transaction. Whether it survives a rollback depends on the queue:

- With Redis, SQS or another queue outside the database, the job survives the rollback.
- With `QUEUE_CONNECTION=database` on the same database connection as the transaction, the job row
  is written inside that transaction and rolled back with it. The report is lost.

When that matters, point [`ERROR_REPORTER_QUEUE_CONNECTION`](configuration.md#queue) at a queue
outside the database, or at a `database` queue on a connection of its own.

## No loops

The package never reports its own failures:

- An exception thrown from inside `SendErrorReport` is not sent again.
- A `SendErrorReport` job that times out, runs out of attempts or fails is not sent again. The same
  failures of your own jobs are sent as usual.
- While a report is being built and handed to the transport, a second exception reported at the
  same moment is skipped.

## Never breaking the host

Building or sending a report never throws into your code. When it fails, for example because the
transport throws, the package logs a warning, "The error reporter failed to report an exception.",
with the exception class, the reason and, when it was built, the scrubbed report, so a report the
transport could not take is not lost. This warning goes to the default log channel, not to
[`log_channel`](configuration.md#log-channel). When logging fails too, the failure is swallowed.
