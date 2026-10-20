<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendErrorReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public readonly array $report,
    ) {}

    public function handle(): void
    {
        $endpoint = config('error-reporter.endpoint');
        $key = config('error-reporter.key');

        if (! is_string($endpoint) || $endpoint === '' || ! is_string($key) || $key === '') {
            $this->fallback('The error collector is not configured.');

            return;
        }

        $timeout = (int) config('error-reporter.timeout', 2);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withToken($key)
                ->post($endpoint, $this->report);
        } catch (ConnectionException $exception) {
            $this->fallback($exception->getMessage());

            return;
        }

        if ($response->failed()) {
            $this->fallback(sprintf('The error collector answered %d.', $response->status()));
        }
    }

    private function fallback(string $reason): void
    {
        $channel = config('error-reporter.log_channel');

        Log::channel(is_string($channel) && $channel !== '' ? $channel : null)->warning('An error report could not be delivered to the collector.', [
            'reason' => $reason,
            'report' => $this->report,
        ]);
    }
}
