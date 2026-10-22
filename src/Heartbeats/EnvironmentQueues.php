<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Heartbeats;

use Illuminate\Support\Env;
use Illuminate\Support\Str;

class EnvironmentQueues
{
    /**
     * @return array<string, array{key: string, token: string, connection: string|null, queue: string}>
     */
    public static function parse(mixed $names): array
    {
        if (! is_string($names) || trim($names) === '') {
            return [];
        }

        $queues = [];
        $environmentNames = [];

        foreach (explode(',', $names) as $name) {
            $name = trim($name);
            $environmentName = self::environmentName($name);

            if ($environmentName === '' || is_numeric($name) || in_array($environmentName, $environmentNames, true)) {
                continue;
            }

            $environmentNames[] = $environmentName;
            $prefix = sprintf('ERROR_REPORTER_%s_HEARTBEAT', $environmentName);
            $key = self::value(sprintf('%s_KEY', $prefix));
            $token = self::value(sprintf('%s_TOKEN', $prefix));

            if (is_null($key) || is_null($token)) {
                continue;
            }

            $queues[$name] = [
                'key' => $key,
                'token' => $token,
                'connection' => self::value(sprintf('%s_CONNECTION', $prefix)),
                'queue' => self::value(sprintf('%s_QUEUE', $prefix)) ?? $name,
            ];
        }

        return $queues;
    }

    public static function environmentName(string $name): string
    {
        return Str::of($name)
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->trim('_')
            ->upper()
            ->toString();
    }

    private static function value(string $variable): ?string
    {
        $value = Env::get($variable);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
