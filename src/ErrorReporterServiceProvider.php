<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use J87NL\ErrorReporter\Contracts\ErrorTransport;
use J87NL\ErrorReporter\Heartbeats\HeartbeatSchedule;
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

        $this->app->singleton(ValueRedactor::class, function (): ValueRedactor {
            $rules = config('error-reporter.redact');
            $rules = is_array($rules) ? $rules : [];

            return ValueRedactor::fromRules(
                (bool) ($rules['secrets'] ?? true),
                (bool) ($rules['personal_data'] ?? false),
                [
                    ...$this->strings(config('error-reporter.scrub')),
                    ...$this->strings($rules['query_parameters'] ?? ValueRedactor::DEFAULT_QUERY_PARAMETERS),
                ],
                $this->validPatterns($this->strings($rules['patterns'] ?? [])),
            );
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

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->app->make(HeartbeatSchedule::class)->register($schedule);
        });
    }

    /**
     * Logs each pattern that is not a valid regular expression once, when the redactor is built.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function validPatterns(array $patterns): array
    {
        $valid = [];

        foreach ($patterns as $pattern) {
            if (ValueRedactor::isValidPattern($pattern)) {
                $valid[] = $pattern;

                continue;
            }

            try {
                Log::warning('The error reporter skipped a redact pattern that is not a valid regular expression.', [
                    'pattern' => $pattern,
                ]);
            } catch (Throwable) {
                continue;
            }
        }

        return $valid;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
