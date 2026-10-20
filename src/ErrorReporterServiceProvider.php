<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\ServiceProvider;
use J87NL\ErrorReporter\Contracts\ErrorTransport;
use J87NL\ErrorReporter\Transports\QueuedHttpTransport;
use Throwable;

class ErrorReporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/error-reporter.php', 'error-reporter');

        $this->app->singleton(ErrorReporter::class);

        $this->app->singleton(Scrubber::class, function (): Scrubber {
            $words = config('error-reporter.scrub', []);

            return new Scrubber(is_array($words) ? array_values(array_filter($words, 'is_string')) : []);
        });

        $this->app->bind(ErrorTransport::class, function (): ErrorTransport {
            $transport = config('error-reporter.transport', QueuedHttpTransport::class);
            $instance = $this->app->make(is_string($transport) ? $transport : QueuedHttpTransport::class);

            return $instance instanceof ErrorTransport ? $instance : $this->app->make(QueuedHttpTransport::class);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/error-reporter.php' => config_path('error-reporter.php'),
        ], 'error-reporter-config');

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! $handler instanceof Handler) {
                return;
            }

            $handler->reportable(function (Throwable $exception): void {
                $this->app->make(ErrorReporter::class)->report($exception);
            });
        });
    }
}
