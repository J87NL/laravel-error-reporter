<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Heartbeats;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use J87NL\ErrorReporter\Scrubber;
use Psr\Log\LoggerInterface;
use Throwable;

class HeartbeatPinger
{
    public function __construct(
        private readonly HeartbeatConfig $config,
    ) {}

    public function ping(string $key, string $token): bool
    {
        $endpoint = $this->config->endpoint();

        if (is_null($endpoint)) {
            return false;
        }

        $url = sprintf('%s/%s', $endpoint, rawurlencode($key));
        $timeout = $this->config->timeout();

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withToken($token)
                ->post($url);
        } catch (Throwable $exception) {
            $this->fail($key, $url, str_replace($token, Scrubber::MASK, $exception->getMessage()));

            return false;
        }

        if ($response->failed()) {
            $this->fail($key, $url, sprintf('The heartbeat collector answered %d.', $response->status()));

            return false;
        }

        $this->recover($key, $url);

        return true;
    }

    private function fail(string $key, string $url, string $reason): void
    {
        if (! $this->startsOutage($key)) {
            return;
        }

        try {
            $this->logger()->warning('A heartbeat could not be delivered to the collector.', [
                'heartbeat' => $key,
                'url' => $url,
                'reason' => $reason,
            ]);
        } catch (Throwable) {
            return;
        }
    }

    private function recover(string $key, string $url): void
    {
        try {
            if (Cache::pull($this->outageKey($key)) !== true) {
                return;
            }

            $this->logger()->info('A heartbeat reaches the collector again.', [
                'heartbeat' => $key,
                'url' => $url,
            ]);
        } catch (Throwable) {
            return;
        }
    }

    private function startsOutage(string $key): bool
    {
        try {
            return Cache::add($this->outageKey($key), true);
        } catch (Throwable) {
            return true;
        }
    }

    private function outageKey(string $key): string
    {
        return sprintf('error-reporter:heartbeat-outage:%s', $key);
    }

    private function logger(): LoggerInterface
    {
        $channel = config('error-reporter.log_channel');

        return Log::channel(is_string($channel) && $channel !== '' ? $channel : null);
    }
}
