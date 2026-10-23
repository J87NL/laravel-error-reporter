# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Value redaction in the message, the URL and every string key and value in the context, before the message is cut and before the fingerprint is computed. Errors that differ only in a masked value now share a fingerprint.
- Secret rules, on by default (`ERROR_REPORTER_REDACT_SECRETS`): `Bearer` and `Basic` credentials, secret parameters (the `scrub` words plus `redact.query_parameters`), passwords in URLs, `base64:` keys, JSON Web Tokens and opaque strings of 32 or more characters. UUIDs, migration names, slugs, class names and compiled views are left alone.
- Personal data rules, opt-in (`ERROR_REPORTER_REDACT_PERSONAL_DATA`): e-mail addresses, IBANs with a valid checksum, also spaced and foreign, and Dutch phone numbers.
- Own regular expressions through `redact.patterns`. An invalid pattern is skipped and logged once.
- `ValueRedactor` in the container, so a collector can apply the same rules to the reports it receives.
- An upgrade section for apps that redacted values themselves: [Moving from your own redactor](docs/scrubbing.md#moving-from-your-own-redactor).

### Changed

- Messages, URLs and context values that match a secret rule are now sent as `[scrubbed]`. Set `ERROR_REPORTER_REDACT_SECRETS=false` for the old behaviour. A published config without the `redact` section keeps working with the defaults.
- Fingerprints change once for errors whose message contains a value that is now masked, so those errors start a new group in the collector after the upgrade.
- `ErrorReport` takes an optional `ValueRedactor` as its second constructor argument and falls back to the one in the container, so `new ErrorReport($scrubber)` and subclasses that call `parent::__construct($scrubber)` keep working.

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
