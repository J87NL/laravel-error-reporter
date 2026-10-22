<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Heartbeats;

use Illuminate\Support\Str;

class HeartbeatConfig
{
    private const string ERRORS_PATH = '/ingest/errors';

    private const string HEARTBEATS_PATH = '/heartbeats';

    public function enabled(): bool
    {
        return (bool) config('error-reporter.heartbeats.enabled', false) && ! is_null($this->endpoint());
    }

    public function endpoint(): ?string
    {
        $configured = config('error-reporter.heartbeats.endpoint');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $errorsEndpoint = config('error-reporter.endpoint');

        if (! is_string($errorsEndpoint) || $errorsEndpoint === '') {
            return null;
        }

        $path = rtrim(Str::before($errorsEndpoint, '?'), '/');

        if (! str_ends_with($path, self::ERRORS_PATH)) {
            return null;
        }

        return Str::replaceEnd(self::ERRORS_PATH, self::HEARTBEATS_PATH, $path);
    }

    public function timeout(): int
    {
        return max(1, (int) config('error-reporter.heartbeats.timeout', 3));
    }

    /**
     * @return array{key: string, token: string}|null
     */
    public function scheduler(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->credentials(config('error-reporter.heartbeats.scheduler'));
    }

    /**
     * @return array<string, array{key: string, token: string, connection: string|null, queue: string|null}>
     */
    public function queues(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $configured = config('error-reporter.heartbeats.queues');
        $fromEnvironment = config('error-reporter.heartbeats.extra_queues');

        $definitions = [
            ...(is_array($fromEnvironment) ? $fromEnvironment : []),
            ...(is_array($configured) ? $configured : []),
        ];

        $queues = [];

        foreach ($definitions as $name => $definition) {
            $queue = $this->queueDefinition($definition);

            if (is_null($queue) || ! is_string($name) || $name === '') {
                continue;
            }

            $queues[$name] = $queue;
        }

        return $queues;
    }

    /**
     * @return array{key: string, token: string, connection: string|null, queue: string|null}|null
     */
    public function queue(string $name): ?array
    {
        return $this->queues()[$name] ?? null;
    }

    /**
     * @return array{key: string, token: string, connection: string|null, queue: string|null}|null
     */
    private function queueDefinition(mixed $definition): ?array
    {
        $credentials = $this->credentials($definition);

        if (is_null($credentials) || ! is_array($definition)) {
            return null;
        }

        return [
            ...$credentials,
            'connection' => $this->optionalString($definition['connection'] ?? null),
            'queue' => $this->optionalString($definition['queue'] ?? null),
        ];
    }

    /**
     * @return array{key: string, token: string}|null
     */
    private function credentials(mixed $definition): ?array
    {
        if (! is_array($definition)) {
            return null;
        }

        $key = $this->optionalString($definition['key'] ?? null);
        $token = $this->optionalString($definition['token'] ?? null);

        if (is_null($key) || is_null($token)) {
            return null;
        }

        return [
            'key' => $key,
            'token' => $token,
        ];
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
