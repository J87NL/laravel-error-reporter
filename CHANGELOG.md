# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Optional heartbeats, off by default: every five minutes the scheduler pings its own heartbeat on the collector, and queues a job per watched queue that pings that queue's heartbeat when a worker handles it.
- A queue heartbeat job is unique per queue, so a stopped worker does not pile up pings.
- A failed ping never throws. It logs one warning per outage without the token, and one info line when the heartbeat gets through again.
- Extra queues through `ERROR_REPORTER_EXTRA_QUEUES`, for example `mailing`, with `ERROR_REPORTER_MAILING_HEARTBEAT_KEY` and `_TOKEN`, without publishing the config.

## [0.1.0] - 2026-10-21

### Added

- Reports every exception the Laravel handler reports to the J87 error collector, through a queued job with a 2-second timeout and a log fallback.
- Sends the fingerprint, class, message, file, line, a trimmed trace with in-app flags, route, method, URL without the query string, user id, environment, release and the exception's context.
- Masks secrets at any depth, secret route parameters in the URL and the bindings of a `QueryException` before anything leaves the app.
- Queues nothing while the collector is not configured, and never reports a failure of its own delivery job.
- A browser script for Vue apps that reports `errorHandler`, `error` and `unhandledrejection` with the public browser key, masking token-like path segments.
- An `ErrorTransport` contract, so a host can choose how reports leave.
- Replaces a scrubbed context larger than `max_context_bytes` by `{"truncated": true}` before it leaves the app.
- Logs the scrubbed report when the transport fails, so the report is not lost.

[Unreleased]: https://github.com/J87NL/laravel-error-reporter/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/J87NL/laravel-error-reporter/releases/tag/v0.1.0
