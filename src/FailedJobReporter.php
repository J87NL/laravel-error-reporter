<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use J87NL\ErrorReporter\Jobs\SendErrorReport;
use Throwable;

class FailedJobReporter
{
    public function __construct(
        private readonly Application $container,
    ) {}

    public function failed(JobFailed $event): void
    {
        if (! $event->exception instanceof Throwable) {
            return;
        }

        $this->report($event->exception, $event->job, $event->connectionName);
    }

    public function timedOut(JobTimedOut $event): void
    {
        try {
            if (! $event->job instanceof Job || $event->job->hasFailed() || ! $this->workerKillsOnTimeout()) {
                return;
            }

            $this->report(TimeoutExceededException::forJob($event->job), $event->job, $event->connectionName);
        } catch (Throwable) {
            return;
        }
    }

    private function report(Throwable $exception, mixed $job, mixed $connectionName): void
    {
        if (! config('error-reporter.jobs.report_failures', true) || ! $job instanceof Job) {
            return;
        }

        try {
            if (is_a((string) $job->resolveName(), SendErrorReport::class, true) || ! $this->container->make(ExceptionHandler::class)->shouldReport($exception)) {
                return;
            }

            $this->container->make(ErrorReporter::class)->reportWithContext($exception, [
                'job' => $this->details($job, $connectionName),
            ]);
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @return array{name: string, queue: string|null, connection: string|null, attempts: int}
     */
    private function details(Job $job, mixed $connectionName): array
    {
        $queue = $job->getQueue();
        $connection = is_string($connectionName) && $connectionName !== '' ? $connectionName : $job->getConnectionName();

        return [
            'name' => (string) $job->resolveName(),
            'queue' => is_string($queue) && $queue !== '' ? $queue : null,
            'connection' => is_string($connection) && $connection !== '' ? $connection : null,
            'attempts' => (int) $job->attempts(),
        ];
    }

    private function workerKillsOnTimeout(): bool
    {
        if (! property_exists(Worker::class, 'killOnTimeout')) {
            return true;
        }

        return Worker::$killOnTimeout !== false;
    }
}
