<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use J87NL\ErrorReporter\Heartbeats\HeartbeatConfig;
use J87NL\ErrorReporter\Heartbeats\HeartbeatPinger;

class PingQueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $heartbeat,
        public readonly string $key,
    ) {}

    public function uniqueId(): string
    {
        return sprintf('%s:%s', $this->heartbeat, $this->key);
    }

    public function displayName(): string
    {
        return sprintf('error-reporter:queue-heartbeat:%s', $this->heartbeat);
    }

    public function handle(HeartbeatConfig $config, HeartbeatPinger $pinger): void
    {
        $definition = $config->queue($this->heartbeat);

        if (is_null($definition)) {
            return;
        }

        $pinger->ping($definition['key'], $definition['token']);
    }
}
