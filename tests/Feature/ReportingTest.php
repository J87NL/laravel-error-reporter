<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use J87NL\ErrorReporter\Contracts\ErrorTransport;
use J87NL\ErrorReporter\ErrorReporter;
use J87NL\ErrorReporter\Jobs\SendErrorReport;
use J87NL\ErrorReporter\Scrubber;

class ExceptionWithContext extends RuntimeException
{
    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'order' => 42,
            'password' => 'hunter2',
            'api_key' => 'abc',
            'nested' => [
                'Authorization' => 'Bearer xyz',
                'session_cookie' => 'c',
                'step' => 'payment',
            ],
            'customer' => new ArrayObject([
                'name' => 'Jan',
                'password' => 'geheim',
            ]),
            'callback' => fn (): bool => true,
        ];
    }
}

function capturedReport(): array
{
    $report = null;

    Queue::assertPushed(SendErrorReport::class, function (SendErrorReport $job) use (&$report): bool {
        $report = $job->report;

        return true;
    });

    return $report;
}

test('an unhandled exception is queued with the fields the collector needs and nothing from the query string', function (): void {
    Queue::fake();
    Route::get('orders/{order}', fn (): never => throw new RuntimeException('Order 42 could not be found'))->name('orders.show');

    $this->actingAs((new User)->forceFill([
        'id' => 7,
    ]))->get('/orders/42?token=geheim');

    $report = capturedReport();

    expect($report)
        ->exception_class->toBe(RuntimeException::class)
        ->message->toBe('Order 42 could not be found')
        ->route->toBe('orders.show')
        ->method->toBe('GET')
        ->url->toBe('http://localhost/orders/42')
        ->user_id->toBe('7')
        ->environment->toBe('production')
        ->release->toBe('abc1234')
        ->and($report['fingerprint'])->toHaveLength(64)
        ->and($report['line'])->toBeInt()
        ->and($report['trace'][0]['in_app'])->toBeTrue()
        ->and(json_encode($report))->not->toContain('geheim');
});

test('sensitive keys are masked on the client before anything leaves the app', function (): void {
    Queue::fake();

    app(ErrorReporter::class)->report(new ExceptionWithContext('Payment failed'));

    expect(capturedReport()['context'])->toBe([
        'order' => 42,
        'password' => Scrubber::MASK,
        'api_key' => Scrubber::MASK,
        'nested' => [
            'Authorization' => Scrubber::MASK,
            'session_cookie' => Scrubber::MASK,
            'step' => 'payment',
        ],
        'customer' => [
            'name' => 'Jan',
            'password' => Scrubber::MASK,
        ],
        'callback' => [],
    ]);
});

test('secret route parameters are masked in the reported address', function (): void {
    Queue::fake();
    Route::get('reset-password/{token}', fn (): never => throw new RuntimeException('Expired'))->name('password.reset');

    $this->get('/reset-password/abc123secret');

    expect(capturedReport()['url'])->toBe(sprintf('http://localhost/reset-password/%s', Scrubber::MASK));
});

test('the queued job posts the report with the app key', function (): void {
    Http::fake([
        'collector.test/*' => Http::response(null, 202),
    ]);

    (new SendErrorReport([
        'exception_class' => RuntimeException::class,
        'message' => 'Boom',
    ]))->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://collector.test/api/ingest/errors'
        && $request->hasHeader('Authorization', 'Bearer server-key')
        && $request['message'] === 'Boom');
});

test('the report falls back to the log when the collector is down or refuses it', function (Closure $collector): void {
    Http::fake([
        'collector.test/*' => $collector,
    ]);
    Log::shouldReceive('channel')->once()->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['report']['message'] === 'Boom');

    (new SendErrorReport([
        'exception_class' => RuntimeException::class,
        'message' => 'Boom',
    ]))->handle();
})->with([
    'down' => [fn (): Closure => fn (): never => throw new ConnectionException('Connection timed out after 2000 milliseconds')],
    'refused' => [fn (): Closure => fn (): PromiseInterface => Http::response(null, 500)],
    'broken' => [fn (): Closure => fn (): never => throw new InvalidArgumentException('Malformed request')],
]);

test('ignored exceptions and a missing browser key are left alone', function (): void {
    Queue::fake();
    config()->set('error-reporter.ignore', [
        InvalidArgumentException::class,
    ]);

    app(ErrorReporter::class)->report(new InvalidArgumentException('Expected'));

    Queue::assertNothingPushed();

    expect(app(ErrorReporter::class)->browserConfig())->toBeNull();

    config()->set('error-reporter.browser_key', 'public-key');

    expect(app(ErrorReporter::class)->browserConfig())->toBe([
        'endpoint' => 'https://collector.test/api/ingest/errors',
        'key' => 'public-key',
        'release' => 'abc1234',
        'environment' => 'production',
    ]);
});

test('only whole path segments of secret route parameters are masked', function (): void {
    Queue::fake();
    Route::get('invites/{token}/{page}', fn (): never => throw new RuntimeException('Expired'))->name('invites.show');

    $this->get('/invites/ab/abc');

    expect(capturedReport()['url'])->toBe(sprintf('http://localhost/invites/%s/abc', Scrubber::MASK));
});

test('nothing is queued while the collector is not configured', function (): void {
    Queue::fake();
    config()->set('error-reporter.endpoint', null);

    app(ErrorReporter::class)->report(new RuntimeException('Boom'));

    Queue::assertNothingPushed();
});

test('the report is marked to be queued without waiting for an open transaction', function (): void {
    Queue::fake();

    app(ErrorReporter::class)->report(new RuntimeException('Boom'));

    Queue::assertPushed(SendErrorReport::class, fn (SendErrorReport $job): bool => $job->afterCommit === false);
});

test('a message with invalid UTF-8 still leaves as valid UTF-8', function (): void {
    Queue::fake();

    app(ErrorReporter::class)->report(new RuntimeException("Bad byte \xB1 in name"));

    expect(mb_check_encoding(capturedReport()['message'], 'UTF-8'))->toBeTrue();
});

test('query bindings and the values in the driver message never leave the app', function (): void {
    Queue::fake();

    app(ErrorReporter::class)->report(new QueryException('testing', 'insert into users (email, password) values (?, ?)', [
        'jan@example.test',
        'hunter2',
    ], new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jan@example.test' for key 'users_email_unique'")));

    $report = capturedReport();

    expect($report['message'])->toContain('insert into users (email, password) values (?, ?)')
        ->and(json_encode($report))->not->toContain('hunter2')
        ->and(json_encode($report))->not->toContain('jan@example.test');
});

test('a delivery job that times out or runs out of attempts is never reported again', function (): void {
    Queue::fake();
    $deliveryJob = Mockery::mock(Job::class);
    $deliveryJob->shouldReceive('resolveName')->andReturn(SendErrorReport::class);
    $otherJob = Mockery::mock(Job::class);
    $otherJob->shouldReceive('resolveName')->andReturn('App\\Jobs\\ImportOrders');

    app(ErrorReporter::class)->report(TimeoutExceededException::forJob($deliveryJob));
    app(ErrorReporter::class)->report(MaxAttemptsExceededException::forJob($deliveryJob));

    Queue::assertNothingPushed();

    app(ErrorReporter::class)->report(MaxAttemptsExceededException::forJob($otherJob));

    Queue::assertPushed(SendErrorReport::class, 1);
});

test('a failure while delivering a report is never reported again', function (): void {
    Queue::fake();
    Http::fake([
        'collector.test/*' => Http::response(null, 500),
    ]);
    Log::shouldReceive('channel')->andReturnUsing(fn (): never => throw new RuntimeException('The log is unavailable'));

    try {
        (new SendErrorReport([
            'message' => 'Boom',
        ]))->handle();
    } catch (RuntimeException $failure) {
        app(ErrorReporter::class)->report($failure);
    }

    Queue::assertNothingPushed();
});

test('a broken transport and a broken log never break the host', function (): void {
    app()->bind(ErrorTransport::class, fn (): ErrorTransport => new class implements ErrorTransport
    {
        public function send(array $report): void
        {
            throw new RuntimeException('Transport down');
        }
    });
    Log::shouldReceive('warning')->once()->andReturnUsing(fn (): never => throw new RuntimeException('The log is unavailable'));

    app(ErrorReporter::class)->report(new RuntimeException('Boom'));
});
