<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ErrorReport
{
    private const int MAX_CONTEXT_DEPTH = 8;

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
            'url' => is_null($request) ? null : $this->url($request),
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

        if (! is_array($context)) {
            return [];
        }

        $plain = json_decode((string) json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE, self::MAX_CONTEXT_DEPTH), true);

        return is_array($plain) ? $plain : [];
    }

    private function url(Request $request): string
    {
        $url = $request->url();

        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            if (! is_string($value) || $value === '' || ! $this->scrubber->isSensitive((string) $name)) {
                continue;
            }

            $url = str_replace(sprintf('/%s', rawurlencode($value)), sprintf('/%s', Scrubber::MASK), $url);
        }

        return $url;
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
