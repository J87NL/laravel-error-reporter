<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ErrorReport
{
    private const int MAX_CONTEXT_DEPTH = 8;

    private const int REDACTION_MARGIN = 1024;

    private readonly ValueRedactor $redactor;

    public function __construct(
        private readonly Scrubber $scrubber,
        ?ValueRedactor $redactor = null,
    ) {
        $this->redactor = $redactor ?? app(ValueRedactor::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function fromThrowable(Throwable $exception, ?Request $request, int|string|null $userId): array
    {
        $frames = $this->frames($exception);
        $topFrame = Grouping::topInAppFrame($frames);
        $message = $this->limitedMessage($this->message($exception));

        return [
            'fingerprint' => Grouping::fingerprint($exception::class, $topFrame, $message),
            'exception_class' => $exception::class,
            'exception_code' => $this->exceptionCode($exception),
            'message' => $message,
            'file' => $this->relativePath($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => $frames,
            'route' => $request?->route()?->getName(),
            'method' => $request?->method(),
            'url' => is_null($request) ? null : $this->redactor->redactString($this->url($request)),
            'referrer' => is_null($request) ? null : $this->referrer($request),
            'ip' => is_null($request) ? null : $this->ip($request),
            'request_body' => is_null($request) ? null : $this->requestBody($request),
            'user_id' => is_null($userId) ? null : (string) $userId,
            'environment' => (string) config('error-reporter.environment'),
            'release' => $this->stringOrNull(config('error-reporter.release')),
            'context' => $this->limitedContext($this->redactedContext($exception)),
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function withContext(array $report, array $context): array
    {
        if ($context === []) {
            return $report;
        }

        $redacted = $this->redactor->redact($this->scrubber->scrub($context));
        $existing = $report['context'] ?? [];

        $report['context'] = $this->limitedContext([
            ...(is_array($existing) ? $existing : []),
            ...(is_array($redacted) ? $redacted : []),
        ]);

        return $report;
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

    private function message(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if ($exception instanceof QueryException) {
            $message = sprintf('SQLSTATE[%s] (Connection: %s, SQL: %s)', $exception->getCode(), $exception->getConnectionName(), $exception->getSql());
        }

        return mb_scrub($message, 'UTF-8');
    }

    private function limitedMessage(string $message): string
    {
        $maxLength = max(0, (int) config('error-reporter.max_message_length', 2000));
        $isCut = mb_strlen($message) > $maxLength + self::REDACTION_MARGIN;

        if ($isCut) {
            $window = mb_substr($message, 0, $maxLength + self::REDACTION_MARGIN);
            $message = preg_match('/^.*\s/su', $window, $match) === 1 && mb_strlen($match[0]) > $maxLength ? $match[0] : $window;
        }

        $redacted = $this->redactor->redactString($message);
        $limited = Str::limit($redacted, $maxLength);

        if ($isCut && mb_strlen($redacted) <= $maxLength) {
            return sprintf('%s...', rtrim($redacted));
        }

        return $limited;
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

    /**
     * @return array<array-key, mixed>
     */
    private function redactedContext(Throwable $exception): array
    {
        $context = $this->redactor->redact($this->limitedContext($this->scrubber->scrub($this->context($exception))));

        return is_array($context) ? $context : [];
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function limitedContext(array $context): array
    {
        if (strlen((string) json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)) <= (int) config('error-reporter.max_context_bytes', 8192)) {
            return $context;
        }

        return [
            'truncated' => true,
        ];
    }

    private function exceptionCode(Throwable $exception): int|string|null
    {
        $code = $exception->getCode();

        if ($code === 0 || $code === '' || $code === '0') {
            return null;
        }

        return is_string($code) ? Str::limit($code, 50, '') : $code;
    }

    private function ip(Request $request): ?string
    {
        return IpMode::fromConfig(config('error-reporter.ip'))->apply($request->ip());
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function requestBody(Request $request): ?array
    {
        return (new RequestBody($this->scrubber, $this->redactor))->fromRequest($request);
    }

    private function referrer(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');

        if (! is_string($referrer) || trim($referrer) === '') {
            return null;
        }

        $withoutQuery = Str::before(Str::before(trim($referrer), '#'), '?');

        return Str::limit($this->redactor->redactString(mb_scrub($withoutQuery, 'UTF-8')), 2048, '');
    }

    private function url(Request $request): string
    {
        $secrets = $this->secretRouteValues($request);

        if ($secrets === []) {
            return $request->url();
        }

        $segments = array_map(
            fn (string $segment): string => in_array(rawurldecode($segment), $secrets, true) ? Scrubber::MASK : $segment,
            explode('/', trim($request->getPathInfo(), '/')),
        );

        return sprintf('%s/%s', $request->root(), implode('/', $segments));
    }

    /**
     * @return list<string>
     */
    private function secretRouteValues(Request $request): array
    {
        $secrets = [];

        foreach ($request->route()?->originalParameters() ?? [] as $name => $value) {
            if (is_string($value) && $value !== '' && $this->scrubber->isSensitive((string) $name)) {
                $secrets[] = $value;
            }
        }

        return $secrets;
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
