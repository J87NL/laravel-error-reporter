<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Transports;

use J87NL\ErrorReporter\Contracts\ErrorTransport;
use J87NL\ErrorReporter\Jobs\SendErrorReport;

class QueuedHttpTransport implements ErrorTransport
{
    public function send(array $report): void
    {
        $job = new SendErrorReport($report);
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
