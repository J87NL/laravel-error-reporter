# Heartbeats

Heartbeats tell the collector that your scheduler and your queue workers are still running. The
collector expects a ping every few minutes and raises an alert when one stays away.

Heartbeats are off by default. Nothing is scheduled until you turn them on and set a key and token.

## What the package schedules

| Task | Runs | Proves |
|---|---|---|
| `error-reporter:scheduler-heartbeat` | every five minutes | `schedule:run` is running. The ping is sent from the scheduler itself |
| `error-reporter:queue-heartbeat:{name}` | every five minutes | a worker processes that queue. The scheduler queues a small job, and the job sends the ping when a worker handles it |

Both are registered on Laravel's scheduler by the service provider. You do not add anything to
`routes/console.php`. Check them with:

```bash
php artisan schedule:list
```

The queue job, `J87NL\ErrorReporter\Jobs\PingQueueHeartbeat`, is unique per queue heartbeat. While
one is still waiting for a worker, the scheduler does not queue another, so a stopped worker does
not leave a pile of pings behind. The lock lasts at most an hour. The job carries
the name and the key of the queue heartbeat. The token is read from the config when it runs, so it
never ends up in the queue.

## 1. Create the heartbeats in the collector

In the j87.nl admin, open **Monitoring → Heartbeats** and choose **Toevoegen** (add). Create one
heartbeat for the scheduler and one per queue you want watched:

| Field | Scheduler | Queue | Mailing queue (example) |
|---|---|---|---|
| Naam (name) | `Shop scheduler` | `Shop queue` | `Shop mailing queue` |
| Sleutel (key) | `shop-scheduler` | `shop-queue` | `shop-mailing-queue` |
| Verwacht elke zoveel minuten (interval) | `5` | `5` | `5` |
| Speling in minuten (grace) | `5` | `10` | `30` |

The key is part of the address and may only hold lowercase letters, digits and single hyphens. The
interval is always `5`, because the package pings every five minutes. The grace is how late a ping
may be before the collector raises an alert:

- **Scheduler:** `5` minutes covers a slow `schedule:run`.
- **Queue:** `10` minutes also covers the time the job waits in the queue.
- **A busy queue**, such as a mailing queue that sends large batches: choose a grace longer than
  the longest batch, because the ping job waits behind every job that was queued before it.

After saving, the admin shows the address and the token **once**. Copy the token into `.env` right
away. Lost it? Choose **Nieuw token** (new token) on the heartbeat; the old token stops working.

## 2. Set the environment variables

```dotenv
ERROR_REPORTER_HEARTBEATS_ENABLED=true
ERROR_REPORTER_SCHEDULER_HEARTBEAT_KEY=shop-scheduler
ERROR_REPORTER_SCHEDULER_HEARTBEAT_TOKEN=hb_...
ERROR_REPORTER_QUEUE_HEARTBEAT_KEY=shop-queue
ERROR_REPORTER_QUEUE_HEARTBEAT_TOKEN=hb_...
```

| Variable | Holds |
|---|---|
| `ERROR_REPORTER_HEARTBEATS_ENABLED` | `true` turns heartbeats on. Default `false` |
| `ERROR_REPORTER_HEARTBEAT_ENDPOINT` | The heartbeat address without the key. Empty derives it from `ERROR_REPORTER_ENDPOINT` |
| `ERROR_REPORTER_SCHEDULER_HEARTBEAT_KEY` | The key of the scheduler heartbeat |
| `ERROR_REPORTER_SCHEDULER_HEARTBEAT_TOKEN` | Its token, sent as `Authorization: Bearer ...` |
| `ERROR_REPORTER_QUEUE_HEARTBEAT_KEY` | The key of the queue heartbeat |
| `ERROR_REPORTER_QUEUE_HEARTBEAT_TOKEN` | Its token |
| `ERROR_REPORTER_QUEUE_HEARTBEAT_CONNECTION` | The queue connection the ping job goes to. Empty uses your default connection |
| `ERROR_REPORTER_QUEUE_HEARTBEAT_QUEUE` | The queue the ping job goes to. Empty uses the connection's default queue |

A heartbeat without a key or a token is skipped, so you can watch only the scheduler, or only the
queue.

### The address

With `ERROR_REPORTER_ENDPOINT=https://j87.nl/api/ingest/errors`, the package pings
`https://j87.nl/api/heartbeats/{key}`: it swaps the trailing `/ingest/errors` for `/heartbeats`. When
your error endpoint does not end in `/ingest/errors`, or you use heartbeats without error reporting,
set the address yourself:

```dotenv
ERROR_REPORTER_HEARTBEAT_ENDPOINT=https://j87.nl/api/heartbeats
```

Without an address, nothing is scheduled.

## 3. Watch more than one queue

The variables above cover one queue. List more queues by name in `ERROR_REPORTER_EXTRA_QUEUES`, and
give each name its own key and token:

```dotenv
ERROR_REPORTER_EXTRA_QUEUES=mailing,reports
ERROR_REPORTER_MAILING_HEARTBEAT_KEY=shop-mailing-queue
ERROR_REPORTER_MAILING_HEARTBEAT_TOKEN=hb_...
ERROR_REPORTER_REPORTS_HEARTBEAT_KEY=shop-reports-queue
ERROR_REPORTER_REPORTS_HEARTBEAT_TOKEN=hb_...
```

For each name the package reads these variables, with the name in upper snake case (`mailing`
becomes `MAILING`, `report-jobs` becomes `REPORT_JOBS`):

| Variable | Holds |
|---|---|
| `ERROR_REPORTER_{NAME}_HEARTBEAT_KEY` | The key of the heartbeat |
| `ERROR_REPORTER_{NAME}_HEARTBEAT_TOKEN` | Its token |
| `ERROR_REPORTER_{NAME}_HEARTBEAT_CONNECTION` | The queue connection. Empty uses your default connection |
| `ERROR_REPORTER_{NAME}_HEARTBEAT_QUEUE` | The queue. Empty uses the name itself, so `mailing` pings from the `mailing` queue |

A name without a key or a token is skipped, and so is a name that is only a number. Names that
lead to the same variables, such as `report-jobs` and `report_jobs`, count once. The name shows up in the task
name, for example `error-reporter:queue-heartbeat:mailing`.

### In a published config

You can also add queues under `heartbeats.queues` in a published config. The array key is the name:

```php
'queues' => [
    'default' => [
        'key' => env('ERROR_REPORTER_QUEUE_HEARTBEAT_KEY'),
        'token' => env('ERROR_REPORTER_QUEUE_HEARTBEAT_TOKEN'),
        'connection' => env('ERROR_REPORTER_QUEUE_HEARTBEAT_CONNECTION'),
        'queue' => env('ERROR_REPORTER_QUEUE_HEARTBEAT_QUEUE'),
    ],
    'imports' => [
        'key' => env('ERROR_REPORTER_IMPORTS_HEARTBEAT_KEY'),
        'token' => env('ERROR_REPORTER_IMPORTS_HEARTBEAT_TOKEN'),
        'connection' => 'redis',
        'queue' => 'imports',
    ],
],
```

Queues from `ERROR_REPORTER_EXTRA_QUEUES` are added to these. When both define the same name, the
entry in `heartbeats.queues` wins.

Laravel merges a published config only on its top-level keys. When your `config/error-reporter.php`
has a `heartbeats` key, it replaces the package's whole `heartbeats` block, so copy the full block,
including `enabled`, `endpoint`, `timeout`, `scheduler` and `extra_queues`.

## Delivery and failures

The ping is a JSON `POST` without data, with the token as a bearer token, with a timeout of three
seconds (`heartbeats.timeout`). It is not retried: the next ping comes five minutes later.

A ping that fails never throws into your app. When the collector cannot be reached or answers with
an error, the package writes a warning to the [log channel](configuration.md#log-channel), with the
heartbeat key, the address and the reason. The token is never logged.

The warning comes once per outage, not once per ping: the package remembers the failure in your
default cache store and stays quiet until a ping gets through again. That ping writes one `info`
line that the heartbeat reaches the collector again. An outage that lasts longer than a day is
logged again once a day. This needs a cache store that outlives a process, such as `redis`,
`database` or `file`. With the `array` store, or when the cache cannot be used, every failed ping
writes a warning.

The collector answers:

| Status | Meaning |
|---|---|
| `204` | The ping is recorded |
| `401` with `{"status": "unknown_heartbeat"}` | No active heartbeat with this key, or a wrong token |
| `429` | Too many pings for this key or from this address. See `Retry-After` |

## Error reporting and heartbeats are separate

`ERROR_REPORTER_ENABLED=false` turns off error reporting, not heartbeats. Heartbeats only follow
`ERROR_REPORTER_HEARTBEATS_ENABLED`.
