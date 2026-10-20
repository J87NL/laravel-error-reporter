<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use J87NL\ErrorReporter\Contracts\ErrorTransport;
use Throwable;

class ErrorReporter
{
    private bool $reporting = false;

    public function __construct(
        private readonly Application $container,
        private readonly ErrorReport $errorReport,
    ) {}

    public function report(Throwable $exception): void
    {
        if ($this->reporting || ! config('error-reporter.enabled', true) || $this->isIgnored($exception)) {
            return;
        }

        $this->reporting = true;

        try {
            $this->container->make(ErrorTransport::class)->send($this->errorReport->fromThrowable($exception, $this->request(), $this->userId()));
        } catch (Throwable $failure) {
            Log::warning('The error reporter failed to report an exception.', [
                'exception' => $exception::class,
                'failure' => $failure->getMessage(),
            ]);
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

    private function request(): ?Request
    {
        if (($this->container->runningInConsole() && ! $this->container->runningUnitTests()) || ! $this->container->bound('request')) {
            return null;
        }

        return $this->container->make(Request::class);
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
