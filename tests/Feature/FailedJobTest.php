<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use J87NL\ErrorReporter\Contracts\ErrorTransport;
use J87NL\ErrorReporter\Grouping;
use J87NL\ErrorReporter\Jobs\SendErrorReport;

class WorkerKilled extends RuntimeException {}

class ImportCustomer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1;

    public function __construct(
        public readonly string $email,
        public readonly bool $hangs = false,
    ) {}

    public function handle(): void
    {
        if ($this->hangs) {
            sleep(5);

            return;
        }

        throw new RuntimeException('The import broke');
    }
}

/**
 * @return array<string, mixed>
 */
function onlyReport(ArrayObject $sent): array
{
    expect($sent)->toHaveCount(1);

    return $sent[0];
}

function queueJob(string $name, bool $hasFailed = false): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('getQueue')->andReturn('imports');
    $job->shouldReceive('getConnectionName')->andReturn('redis');
    $job->shouldReceive('attempts')->andReturn(2);
    $job->shouldReceive('hasFailed')->andReturn($hasFailed);
    $job->shouldReceive('payload')->andReturn([
        'data' => [
            'command' => 'jan@example.test',
        ],
    ]);

    return $job;
}

beforeEach(function (): void {
    $this->sent = new ArrayObject;
    $sent = $this->sent;

    app()->bind(ErrorTransport::class, fn (): ErrorTransport => new class($sent) implements ErrorTransport
    {
        public function __construct(
            private readonly ArrayObject $sent,
        ) {}

        public function send(array $report): void
        {
            if ($report['exception_class'] === WorkerKilled::class) {
                return;
            }

            $this->sent->append($report);
        }
    });

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'imports',
        'retry_after' => 90,
    ]);
    config()->set('queue.failed', [
        'driver' => 'null',
    ]);

    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

afterEach(function (): void {
    Worker::killUsing(null);

    if (property_exists(Worker::class, 'killOnTimeout')) {
        Worker::$killOnTimeout = true;
    }
});

function work(): void
{
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => 'imports',
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--memory' => 1024,
    ]);
}

test('a job that throws is reported once, with the job and without its payload', function (): void {
    dispatch(new ImportCustomer('jan@example.test'));

    work();

    $report = onlyReport($this->sent);

    expect($report)
        ->exception_class->toBe(RuntimeException::class)
        ->message->toBe('The import broke')
        ->and($report['context']['job'])->toBe([
            'name' => ImportCustomer::class,
            'queue' => 'imports',
            'connection' => 'database',
            'attempts' => 1,
        ])
        ->and(json_encode($report))->not->toContain('jan@example.test');
});

test('a job that times out is reported before the worker is killed', function (): void {
    $reportsWhenKilled = null;
    $sent = $this->sent;

    Worker::killUsing(function () use (&$reportsWhenKilled, $sent): never {
        $reportsWhenKilled = $sent->count();

        throw new WorkerKilled('Killed');
    });

    dispatch(new ImportCustomer('jan@example.test', hangs: true));

    work();

    $report = onlyReport($this->sent);

    expect($reportsWhenKilled)->toBe(1)
        ->and($report)
        ->exception_class->toBe(TimeoutExceededException::class)
        ->message->toBe(sprintf('%s has timed out.', ImportCustomer::class))
        ->and($report['context']['job']['name'])->toBe(ImportCustomer::class)
        ->and(json_encode($report))->not->toContain('jan@example.test');
})->skip(! extension_loaded('pcntl'), 'The worker needs pcntl to time out a job.');

test('a timed out attempt that will be retried is reported, and a failed one only once', function (): void {
    event(new JobTimedOut('redis', queueJob('App\\Jobs\\SyncFeed')));
    event(new JobTimedOut('redis', queueJob('App\\Jobs\\SyncFeed', hasFailed: true)));

    $report = onlyReport($this->sent);

    expect($report)
        ->exception_class->toBe(TimeoutExceededException::class)
        ->and($report['context']['job'])->toBe([
            'name' => 'App\\Jobs\\SyncFeed',
            'queue' => 'imports',
            'connection' => 'redis',
            'attempts' => 2,
        ])
        ->and(json_encode($report))->not->toContain('jan@example.test');
});

test('a worker that does not kill on timeout leaves the timeout to the handler', function (): void {
    Worker::$killOnTimeout = false;

    event(new JobTimedOut('redis', queueJob('App\\Jobs\\SyncFeed')));

    expect($this->sent)->toHaveCount(0);
})->skip(! property_exists(Worker::class, 'killOnTimeout'), 'Only Laravel 13 can keep the worker alive on a timeout.');

test('timeouts of one job share a fingerprint wherever the job was stopped', function (): void {
    $message = 'App\\Jobs\\SyncFeed has timed out.';
    $inFeed = [
        'file' => 'app/Jobs/SyncFeed.php',
        'class' => 'App\\Jobs\\SyncFeed',
        'function' => 'handle',
    ];
    $inClient = [
        'file' => 'app/Services/FeedClient.php',
        'class' => 'App\\Services\\FeedClient',
        'function' => 'fetch',
    ];

    expect(Grouping::fingerprint(TimeoutExceededException::class, $inFeed, $message))
        ->toBe(Grouping::fingerprint(TimeoutExceededException::class, $inClient, $message))
        ->not->toBe(Grouping::fingerprint(TimeoutExceededException::class, $inFeed, 'App\\Jobs\\SendInvoice has timed out.'))
        ->not->toBe(Grouping::fingerprint(TimeoutExceededException::class, $inFeed, 'App\\Jobs\\SyncFeed2 has timed out.'))
        ->and(Grouping::fingerprint(RuntimeException::class, $inFeed, $message))
        ->not->toBe(Grouping::fingerprint(RuntimeException::class, $inClient, $message));
});

test('failed jobs are not reported when switched off, ignored or not reported by the handler', function (string $reason): void {
    match ($reason) {
        'switched off' => config()->set('error-reporter.jobs.report_failures', false),
        'ignored' => config()->set('error-reporter.ignore', [
            TimeoutExceededException::class,
        ]),
        'dont report' => app(ExceptionHandler::class)->dontReport(TimeoutExceededException::class),
    };
    $job = queueJob('App\\Jobs\\SyncFeed');

    event(new JobFailed('redis', $job, TimeoutExceededException::forJob($job)));
    event(new JobTimedOut('redis', $job));

    expect($this->sent)->toHaveCount(0);
})->with([
    'switched off',
    'ignored',
    'dont report',
]);

test('a failed delivery job is never reported', function (): void {
    $job = queueJob(SendErrorReport::class);

    event(new JobFailed('redis', $job, new RuntimeException('Collector down')));

    expect($this->sent)->toHaveCount(0);
});
