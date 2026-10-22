# Installation

## Requirements

- PHP 8.5+
- Laravel 13
- A queue worker, for production use
- For browser errors: Vue 3, and Inertia.js to share the browser config

## 1. Install via Composer

```bash
composer require j87nl/laravel-error-reporter
```

The service provider `J87NL\ErrorReporter\ErrorReporterServiceProvider` is auto-discovered.

## 2. Set the environment variables

When you add your app to the collector, its admin shows a server key and a browser key once. Add
them to `.env`, together with the collector address:

```dotenv
ERROR_REPORTER_ENDPOINT=https://j87.nl/api/ingest/errors
ERROR_REPORTER_KEY=srv_...
ERROR_REPORTER_BROWSER_KEY=pub_...
APP_RELEASE=
```

| Variable | Holds |
|---|---|
| `ERROR_REPORTER_ENDPOINT` | The collector's ingest address |
| `ERROR_REPORTER_KEY` | The secret server key, sent as a bearer token by the queued job |
| `ERROR_REPORTER_BROWSER_KEY` | The public browser key, sent by the browser script |
| `APP_RELEASE` | The git SHA of the deployed release, so errors can be tied to a deploy |

While the endpoint or the server key is empty, the default transport queues nothing and sends
nothing. You can install the package first and add the keys later.

## 3. Run a queue worker

Reports are sent by a queued job, `J87NL\ErrorReporter\Jobs\SendErrorReport`. With the `sync`
queue the request waits for the collector, at most the [timeout](configuration.md#timeout) of two
seconds. Use a real queue in production.

To send the reports on their own connection or queue:

```dotenv
ERROR_REPORTER_QUEUE_CONNECTION=redis
ERROR_REPORTER_QUEUE=errors
```

## 4. Publish the config (optional)

The environment variables cover the common settings. To change the scrub list, the ignored
exceptions or the limits, publish the config file:

```bash
php artisan vendor:publish --tag=error-reporter-config
```

This creates `config/error-reporter.php`. See [Configuration](configuration.md) for all options.

## 5. Browser errors (optional)

To report errors from your Vue front-end as well, follow [Vue Usage](browser-errors.md).

## 6. Heartbeats (optional)

To let the collector watch your scheduler and queue workers, create the heartbeats in the collector's
admin and add their keys and tokens to `.env`:

```dotenv
ERROR_REPORTER_HEARTBEATS_ENABLED=true
ERROR_REPORTER_SCHEDULER_HEARTBEAT_KEY=shop-scheduler
ERROR_REPORTER_SCHEDULER_HEARTBEAT_TOKEN=hb_...
ERROR_REPORTER_QUEUE_HEARTBEAT_KEY=shop-queue
ERROR_REPORTER_QUEUE_HEARTBEAT_TOKEN=hb_...
```

The scheduler must run `schedule:run` every minute. See [Heartbeats](heartbeats.md).
