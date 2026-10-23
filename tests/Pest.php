<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use J87NL\ErrorReporter\Jobs\SendErrorReport;
use J87NL\ErrorReporter\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * @return array<string, mixed>
 */
function capturedReport(): array
{
    $report = null;

    Queue::assertPushed(SendErrorReport::class, function (SendErrorReport $job) use (&$report): bool {
        $report = $job->report;

        return true;
    });

    return $report;
}
