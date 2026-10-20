<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use J87NL\ErrorReporter\ErrorReporterServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ErrorReporterServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('error-reporter.endpoint', 'https://collector.test/api/ingest/errors');
        $app['config']->set('error-reporter.key', 'server-key');
        $app['config']->set('error-reporter.release', 'abc1234');
        $app['config']->set('error-reporter.environment', 'production');
    }
}
