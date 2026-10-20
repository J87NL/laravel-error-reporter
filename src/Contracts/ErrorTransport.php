<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Contracts;

interface ErrorTransport
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function send(array $report): void;
}
