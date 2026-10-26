<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Queue\MaxAttemptsExceededException;

class Grouping
{
    private const array PATTERNS = [
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
        '/[\w.+-]+@[\w-]+(\.[\w-]+)+/' => '{email}',
        '/https?:\/\/\S+/' => '{url}',
        '/"[^"]*"/' => '"{string}"',
        "/'[^']*'/" => "'{string}'",
        '/\b0x[0-9a-f]+\b/i' => '{hex}',
        '/\b[0-9a-f]{16,}\b/i' => '{hex}',
        '/\d+(\.\d+)?/' => '{number}',
    ];

    public static function normalizeMessage(string $message): string
    {
        $normalized = $message;

        foreach (self::PATTERNS as $pattern => $replacement) {
            $normalized = (string) preg_replace($pattern, $replacement, $normalized);
        }

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    /**
     * @param  array<string, mixed>|null  $frame
     */
    public static function fingerprint(string $exceptionClass, ?array $frame, string $message): string
    {
        if (self::groupsByJob($exceptionClass)) {
            return hash('sha256', implode('|', [
                $exceptionClass,
                '',
                '',
                trim($message),
            ]));
        }

        return hash('sha256', implode('|', [
            $exceptionClass,
            (string) ($frame['file'] ?? ''),
            sprintf('%s%s', (string) ($frame['class'] ?? ''), (string) ($frame['function'] ?? '')),
            self::normalizeMessage($message),
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @return array<string, mixed>|null
     */
    public static function topInAppFrame(array $frames): ?array
    {
        foreach ($frames as $frame) {
            if (($frame['in_app'] ?? false) === true) {
                return $frame;
            }
        }

        return $frames[0] ?? null;
    }

    public static function groupsByJob(string $exceptionClass): bool
    {
        return is_a($exceptionClass, MaxAttemptsExceededException::class, true);
    }
}
