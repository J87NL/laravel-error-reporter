<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Heartbeats;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use J87NL\ErrorReporter\Scrubber;
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
            $this->warn($key, $url, str_replace($token, Scrubber::MASK, $exception->getMessage()));

            return false;
        }

        if ($response->failed()) {
            $this->warn($key, $url, sprintf('The heartbeat collector answered %d.', $response->status()));

            return false;
        }

        return true;
    }

    private function warn(string $key, string $url, string $reason): void
    {
        try {
            $channel = config('error-reporter.log_channel');

            Log::channel(is_string($channel) && $channel !== '' ? $channel : null)
                ->warning('A heartbeat could not be delivered to the collector.', [
                    'heartbeat' => $key,
                    'url' => $url,
                    'reason' => $reason,
                ]);
        } catch (Throwable) {
            return;
        }
    }
}
