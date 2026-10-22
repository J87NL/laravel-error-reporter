<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use J87NL\ErrorReporter\Heartbeats\HeartbeatSchedule;
use J87NL\ErrorReporter\Jobs\PingQueueHeartbeat;

function configureHeartbeats(array $overrides = []): void
{
    config()->set('error-reporter.heartbeats', array_replace([
        'enabled' => true,
        'endpoint' => null,
        'timeout' => 3,
        'scheduler' => [
            'key' => 'shop-scheduler',
            'token' => 'hb_scheduler_secret',
        ],
        'queues' => [
            'default' => [
                'key' => 'shop-queue',
                'token' => 'hb_queue_secret',
                'connection' => null,
                'queue' => null,
            ],
        ],
    ], $overrides));
}

function heartbeatEvents(): array
{
    app()->forgetInstance(Schedule::class);

    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_starts_with((string) $event->description, 'error-reporter:'))
        ->keyBy(fn (Event $event): string => (string) $event->description)
        ->all();
}

test('nothing is scheduled while heartbeats are off, even with keys and tokens set', function (): void {
    configureHeartbeats([
        'enabled' => false,
    ]);

    expect(heartbeatEvents())->toBe([]);
});

test('nothing is scheduled without a heartbeat endpoint or without a key and token', function (): void {
    configureHeartbeats();
    config()->set('error-reporter.endpoint', null);

    expect(heartbeatEvents())->toBe([]);

    configureHeartbeats([
        'scheduler' => [
            'key' => 'shop-scheduler',
            'token' => '',
        ],
        'queues' => [
            'default' => [
                'key' => null,
                'token' => 'hb_queue_secret',
            ],
        ],
    ]);
    config()->set('error-reporter.endpoint', 'https://collector.test/api/ingest/errors');

    expect(heartbeatEvents())->toBe([]);
});

test('the scheduler heartbeat runs every five minutes and pings the derived address with its token', function (): void {
    configureHeartbeats([
        'queues' => [],
    ]);
    Http::fake([
        'collector.test/*' => Http::response(null, 204),
    ]);

    $events = heartbeatEvents();

    expect($events)->toHaveKeys([
        HeartbeatSchedule::SCHEDULER_EVENT,
    ])->toHaveCount(1)
        ->and($events[HeartbeatSchedule::SCHEDULER_EVENT]->expression)->toBe('*/5 * * * *');

    $events[HeartbeatSchedule::SCHEDULER_EVENT]->run(app());

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://collector.test/api/heartbeats/shop-scheduler'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer hb_scheduler_secret'));
});

test('an explicit heartbeat endpoint wins over the derived one', function (): void {
    configureHeartbeats([
        'endpoint' => 'https://beats.test/api/heartbeats/',
        'queues' => [],
    ]);
    config()->set('error-reporter.endpoint', null);
    Http::fake([
        'beats.test/*' => Http::response(null, 204),
    ]);

    heartbeatEvents()[HeartbeatSchedule::SCHEDULER_EVENT]->run(app());

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://beats.test/api/heartbeats/shop-scheduler');
});

test('every configured queue gets its own job on its own connection and queue, which pings when a worker handles it', function (): void {
    configureHeartbeats([
        'scheduler' => [],
        'queues' => [
            'default' => [
                'key' => 'shop-queue',
                'token' => 'hb_queue_secret',
            ],
            'mailing' => [
                'key' => 'shop-mailing-queue',
                'token' => 'hb_mailing_secret',
                'connection' => 'database',
                'queue' => 'mailing',
            ],
        ],
    ]);
    Queue::fake();
    Http::fake([
        'collector.test/*' => Http::response(null, 204),
    ]);

    $events = heartbeatEvents();

    expect(array_keys($events))->toBe([
        'error-reporter:queue-heartbeat:default',
        'error-reporter:queue-heartbeat:mailing',
    ])
        ->and($events['error-reporter:queue-heartbeat:mailing']->expression)->toBe('*/5 * * * *');

    foreach ($events as $event) {
        $event->run(app());
    }

    Queue::assertPushed(PingQueueHeartbeat::class, 2);
    Queue::assertPushed(PingQueueHeartbeat::class, fn (PingQueueHeartbeat $job): bool => $job->heartbeat === 'mailing'
        && $job->connection === 'database'
        && $job->queue === 'mailing');
    Queue::assertPushed(PingQueueHeartbeat::class, fn (PingQueueHeartbeat $job): bool => $job->heartbeat === 'default'
        && is_null($job->connection)
        && is_null($job->queue));
    Queue::assertPushed(PingQueueHeartbeat::class, fn (PingQueueHeartbeat $job): bool => ! str_contains(serialize($job), 'secret'));

    Http::assertNothingSent();

    app()->call([new PingQueueHeartbeat('mailing'), 'handle']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://collector.test/api/heartbeats/shop-mailing-queue'
        && $request->hasHeader('Authorization', 'Bearer hb_mailing_secret'));
});

test('a queue heartbeat that is still waiting for a worker is not queued again', function (): void {
    configureHeartbeats([
        'scheduler' => [],
    ]);
    Queue::fake();

    $event = heartbeatEvents()['error-reporter:queue-heartbeat:default'];
    $event->run(app());
    $event->run(app());

    Queue::assertPushed(PingQueueHeartbeat::class, 1);
});

test('a queue heartbeat removed from the config after it was queued does not ping', function (): void {
    configureHeartbeats([
        'queues' => [],
    ]);
    Http::fake();

    app()->call([new PingQueueHeartbeat('default'), 'handle']);

    Http::assertNothingSent();
});

test('a collector that is down or refuses the ping never throws and logs one warning without the token', function (Closure $collector): void {
    configureHeartbeats([
        'queues' => [],
    ]);
    Http::fake([
        'collector.test/*' => $collector,
    ]);
    Log::shouldReceive('channel')->once()->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['heartbeat'] === 'shop-scheduler'
        && $context['url'] === 'https://collector.test/api/heartbeats/shop-scheduler'
        && ! str_contains((string) json_encode($context), 'hb_scheduler_secret'));

    heartbeatEvents()[HeartbeatSchedule::SCHEDULER_EVENT]->run(app());
})->with([
    'down' => [fn (): Closure => fn (): never => throw new ConnectionException('Connection timed out')],
    'leaky' => [fn (): Closure => fn (): never => throw new RuntimeException('Rejected token hb_scheduler_secret')],
    'refused' => [fn (): Closure => fn (): PromiseInterface => Http::response(null, 401)],
]);

test('a broken log never breaks the scheduler', function (): void {
    configureHeartbeats([
        'queues' => [],
    ]);
    Http::fake([
        'collector.test/*' => Http::response(null, 500),
    ]);
    Log::shouldReceive('channel')->andReturnUsing(fn (): never => throw new RuntimeException('The log is unavailable'));

    heartbeatEvents()[HeartbeatSchedule::SCHEDULER_EVENT]->run(app());

    Http::assertSentCount(1);
});
