# Testing

## Keeping tests quiet

Your test suite should not send reports to the collector. Turn the package off in `phpunit.xml`:

```xml
<php>
    <env name="ERROR_REPORTER_ENABLED" value="false"/>
</php>
```

Leaving `ERROR_REPORTER_ENDPOINT` or `ERROR_REPORTER_KEY` empty in your test environment has the
same effect for server errors: the default transport then queues nothing.

[Heartbeats](heartbeats.md) only run from the scheduler, so a test suite never pings them. To keep
them out of `schedule:list` in tests as well, set `ERROR_REPORTER_HEARTBEATS_ENABLED` to `false`.

## Asserting that an error is reported

With the package enabled and an endpoint and key set, fake the queue and assert the
`SendErrorReport` job. Its public `$report` property holds the report:

```php
use Illuminate\Support\Facades\Queue;
use J87NL\ErrorReporter\Jobs\SendErrorReport;

test('a failed import is reported', function (): void {
    Queue::fake();
    config()->set('error-reporter.enabled', true);
    config()->set('error-reporter.endpoint', 'https://collector.test/api/ingest/errors');
    config()->set('error-reporter.key', 'server-key');

    report(new RuntimeException('Import failed'));

    Queue::assertPushed(SendErrorReport::class, fn (SendErrorReport $job): bool => $job->report['message'] === 'Import failed');
});
```

`Queue::fake()` keeps the job from running, so no HTTP request is made.

## Testing the delivery

To run the job against a fake collector, call `handle()` with `Http::fake()`:

```php
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use J87NL\ErrorReporter\Jobs\SendErrorReport;

config()->set('error-reporter.endpoint', 'https://collector.test/api/ingest/errors');
config()->set('error-reporter.key', 'server-key');

Http::fake([
    'collector.test/*' => Http::response(['status' => 'accepted'], 202),
]);

(new SendErrorReport([
    'exception_class' => RuntimeException::class,
    'message' => 'Boom',
]))->handle();

Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer server-key'));
```

## Using a test transport

To capture reports without the queue, bind your own [transport](transports.md) in the test:

```php
use J87NL\ErrorReporter\Contracts\ErrorTransport;

$transport = new class implements ErrorTransport
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $reports = [];

    public function send(array $report): void
    {
        $this->reports[] = $report;
    }
};

config()->set('error-reporter.enabled', true);
app()->instance(ErrorTransport::class, $transport);

report(new RuntimeException('Boom'));

expect($transport->reports)->toHaveCount(1);
```

## The package's own tests

The package is tested with Pest and Orchestra Testbench:

```bash
composer install
vendor/bin/pest
```
