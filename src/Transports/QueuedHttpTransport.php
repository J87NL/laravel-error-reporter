<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Transports;

use J87NL\ErrorReporter\Contracts\ErrorTransport;
use J87NL\ErrorReporter\Jobs\SendErrorReport;

class QueuedHttpTransport implements ErrorTransport
{
    public function send(array $report): void
    {
        $endpoint = config('error-reporter.endpoint');
        $key = config('error-reporter.key');

        if (! is_string($endpoint) || $endpoint === '' || ! is_string($key) || $key === '') {
            return;
        }

        $job = (new SendErrorReport($report))->beforeCommit();
        $connection = config('error-reporter.queue.connection');
        $queue = config('error-reporter.queue.name');

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        dispatch($job);
    }
}
