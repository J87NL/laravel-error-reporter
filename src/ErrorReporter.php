<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use J87NL\ErrorReporter\Contracts\ErrorTransport;
use J87NL\ErrorReporter\Jobs\SendErrorReport;
use Throwable;
use WeakMap;

class ErrorReporter
{
    private bool $reporting = false;

    /**
     * @var WeakMap<Throwable, true>
     */
    private WeakMap $reported;

    public function __construct(
        private readonly Application $container,
        private readonly ErrorReport $errorReport,
    ) {
        $this->reported = new WeakMap;
    }

    public function report(Throwable $exception): void
    {
        $this->reportWithContext($exception, []);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function reportWithContext(Throwable $exception, array $context): void
    {
        if ($this->reporting || isset($this->reported[$exception]) || ! config('error-reporter.enabled', true) || $this->isIgnored($exception) || $this->isOwnFailure($exception)) {
            return;
        }

        $this->reporting = true;
        $this->reported[$exception] = true;
        $report = null;

        try {
            $report = $this->errorReport->withContext($this->errorReport->fromThrowable($exception, $this->request(), $this->userId()), $context);

            $this->container->make(ErrorTransport::class)->send($report);
        } catch (Throwable $failure) {
            $this->warn($exception, $failure, $report);
        } finally {
            $this->reporting = false;
        }
    }

    /**
     * @return array{endpoint: string, key: string, release: string|null, environment: string}|null
     */
    public function browserConfig(): ?array
    {
        $endpoint = config('error-reporter.endpoint');
        $key = config('error-reporter.browser_key');

        if (! config('error-reporter.enabled', true) || ! is_string($endpoint) || $endpoint === '' || ! is_string($key) || $key === '') {
            return null;
        }

        $release = config('error-reporter.release');

        return [
            'endpoint' => $endpoint,
            'key' => $key,
            'release' => is_string($release) && $release !== '' ? $release : null,
            'environment' => (string) config('error-reporter.environment'),
        ];
    }

    private function isIgnored(Throwable $exception): bool
    {
        $ignored = config('error-reporter.ignore', []);

        foreach (is_array($ignored) ? $ignored : [] as $class) {
            if (is_string($class) && $exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function isOwnFailure(Throwable $exception): bool
    {
        if ($exception instanceof MaxAttemptsExceededException) {
            return $exception->job?->resolveName() === SendErrorReport::class;
        }

        foreach ($exception->getTrace() as $frame) {
            if (($frame['class'] ?? null) === SendErrorReport::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $report
     */
    private function warn(Throwable $exception, Throwable $failure, ?array $report): void
    {
        try {
            Log::warning('The error reporter failed to report an exception.', array_filter([
                'exception' => $exception::class,
                'failure' => $failure->getMessage(),
                'report' => $report,
            ], fn (mixed $value): bool => ! is_null($value)));
        } catch (Throwable) {
            return;
        }
    }

    private function request(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make(Request::class);

        if ($this->container->runningInConsole() && is_null($request->route())) {
            return null;
        }

        return $request;
    }

    private function userId(): int|string|null
    {
        if (! $this->container->bound('auth')) {
            return null;
        }

        try {
            $id = $this->container->make(AuthFactory::class)->guard()->id();
        } catch (Throwable) {
            return null;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }
}
