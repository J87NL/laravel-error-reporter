<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ErrorReport
{
    public function __construct(
        private readonly Scrubber $scrubber,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fromThrowable(Throwable $exception, ?Request $request, int|string|null $userId): array
    {
        $frames = $this->frames($exception);
        $topFrame = Grouping::topInAppFrame($frames);
        $message = Str::limit($exception->getMessage(), (int) config('error-reporter.max_message_length', 2000));

        return [
            'fingerprint' => Grouping::fingerprint($exception::class, $topFrame, $message),
            'exception_class' => $exception::class,
            'message' => $message,
            'file' => $this->relativePath($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => $frames,
            'route' => $request?->route()?->getName(),
            'method' => $request?->method(),
            'url' => is_null($request) ? null : $request->url(),
            'user_id' => is_null($userId) ? null : (string) $userId,
            'environment' => (string) config('error-reporter.environment'),
            'release' => $this->stringOrNull(config('error-reporter.release')),
            'context' => $this->scrubber->scrub($this->context($exception)),
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array{file: string|null, line: int|null, function: string|null, class: string|null, in_app: bool}>
     */
    private function frames(Throwable $exception): array
    {
        $frames = [
            [
                'file' => $this->relativePath($exception->getFile()),
                'line' => $exception->getLine(),
                'function' => null,
                'class' => null,
                'in_app' => $this->isInApp($exception->getFile()),
            ],
        ];

        foreach ($exception->getTrace() as $frame) {
            $file = is_string($frame['file'] ?? null) ? $frame['file'] : null;

            $frames[] = [
                'file' => is_null($file) ? null : $this->relativePath($file),
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : null,
                'function' => $frame['function'],
                'class' => is_string($frame['class'] ?? null) ? $frame['class'] : null,
                'in_app' => ! is_null($file) && $this->isInApp($file),
            ];
        }

        return array_slice($frames, 0, max(1, (int) config('error-reporter.max_frames', 30)));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function context(Throwable $exception): array
    {
        if (! method_exists($exception, 'context')) {
            return [];
        }

        $context = $exception->context();

        return is_array($context) ? $context : [];
    }

    private function isInApp(string $file): bool
    {
        $relative = $this->relativePath($file);

        return ! str_starts_with($relative, 'vendor/') && ! str_contains($relative, '/vendor/');
    }

    private function relativePath(string $file): string
    {
        $base = rtrim(base_path(), '/');

        return str_starts_with($file, sprintf('%s/', $base)) ? substr($file, strlen($base) + 1) : $file;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
