<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Heartbeats;

use Illuminate\Console\Scheduling\Schedule;
use J87NL\ErrorReporter\Jobs\PingQueueHeartbeat;

class HeartbeatSchedule
{
    public const string SCHEDULER_EVENT = 'error-reporter:scheduler-heartbeat';

    public function __construct(
        private readonly HeartbeatConfig $config,
    ) {}

    public function register(Schedule $schedule): void
    {
        if (! $this->config->enabled()) {
            return;
        }

        if (! is_null($this->config->scheduler())) {
            $schedule->call(function (HeartbeatConfig $config, HeartbeatPinger $pinger): void {
                $scheduler = $config->scheduler();

                if (is_null($scheduler)) {
                    return;
                }

                $pinger->ping($scheduler['key'], $scheduler['token']);
            })
                ->name(self::SCHEDULER_EVENT)
                ->everyFiveMinutes();
        }

        foreach ($this->config->queues() as $name => $queue) {
            $job = new PingQueueHeartbeat($name, $queue['key']);

            $schedule->job($job, $queue['queue'], $queue['connection'])
                ->name($job->displayName())
                ->everyFiveMinutes();
        }
    }
}
