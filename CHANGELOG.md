# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Value redaction in the message, the URL and every string in the context, before the message is cut and before the fingerprint is computed. Errors that differ only in a masked value now share a fingerprint.
- Secret rules, on by default (`ERROR_REPORTER_REDACT_SECRETS`): `Bearer` and `Basic` values, secret query parameters (the `scrub` words plus `redact.query_parameters`), JSON Web Tokens and opaque strings of 32 or more characters that contain a digit.
- Personal data rules, opt-in (`ERROR_REPORTER_REDACT_PERSONAL_DATA`): e-mail addresses, IBANs, also spaced and foreign, and Dutch phone numbers.
- Own regular expressions through `redact.patterns`.
- `ValueRedactor` in the container, so a collector can apply the same rules to the reports it receives.

### Changed

- Messages, URLs and context values that match a secret rule are now sent as `[scrubbed]`. Set `ERROR_REPORTER_REDACT_SECRETS=false` for the old behaviour. A published config without the `redact` section keeps working with the defaults.
- `ErrorReport` takes a `ValueRedactor` as its second constructor argument. A subclass resolved from the container needs no change.

## [0.2.1] - 2026-10-22

### Changed

- Supports Laravel 12 next to Laravel 13. The tests run on both.

## [0.2.0] - 2026-10-22

### Added

- Optional heartbeats, off by default: every five minutes the scheduler pings its own heartbeat on the collector, and queues a job per watched queue that pings that queue's heartbeat when a worker handles it.
- A queue heartbeat job is unique per queue, so a stopped worker does not pile up pings.
- A failed ping never throws. It logs one warning per outage (repeated once a day while it lasts) without the token, and one info line when the heartbeat gets through again.
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

[Unreleased]: https://github.com/J87NL/laravel-error-reporter/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/J87NL/laravel-error-reporter/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/J87NL/laravel-error-reporter/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/J87NL/laravel-error-reporter/releases/tag/v0.1.0
