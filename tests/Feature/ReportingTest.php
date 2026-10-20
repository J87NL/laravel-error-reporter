<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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
    Route::get('orders/{order}', fn () => throw new RuntimeException('Order 42 could not be found'))->name('orders.show');

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
    Route::get('reset-password/{token}', fn () => throw new RuntimeException('Expired'))->name('password.reset');

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
    'down' => [fn () => fn () => throw new ConnectionException('Connection timed out after 2000 milliseconds')],
    'refused' => [fn () => fn () => Http::response(null, 500)],
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
