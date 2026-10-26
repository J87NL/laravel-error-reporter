# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [0.5.0] - 2026-10-26

### Added

- Failed queued jobs are reported, also when they never pass the exception handler. The package listens to `JobFailed` and `JobTimedOut`, which the worker fires before it kills a timed-out process, so a job that runs past its timeout is now reported as a `TimeoutExceededException`. A timed-out attempt that will be retried is reported too. See [Failed jobs](docs/server-errors.md#failed-jobs).
- `context.job` on these reports, with the job's display name, queue, connection and attempts. The job's payload and arguments are never sent.
- `jobs.report_failures` (`ERROR_REPORTER_REPORT_FAILED_JOBS`, default `true`) to turn it off. `ignore`, the handler's `dontReport` and `enabled` apply as usual.
- `ErrorReporter::reportWithContext()`, which merges extra context into the report after scrubbing and redaction. `ErrorReport::withContext()` does the merge.
- `Grouping::groupsByJob()`.

### Changed

- An exception is sent at most once, even when it is reported twice: a job that throws on its last attempt is no longer sent again when the worker passes the same exception to the handler.
- `TimeoutExceededException` and `MaxAttemptsExceededException` are grouped by class and exact message (which holds the job name), no longer by the top stack frame or the normalized message, so every timeout of one job lands in one group. Existing groups of these exceptions get a new fingerprint once. The collector computes the fingerprint with `Grouping`, so it groups this way once it runs this version.

## [0.4.0] - 2026-10-23

### Added

- The request input on server reports (`request_body`): form fields or JSON, not the query string. Sensitive keys are masked at any depth and the value rules run over what is left. Uploaded files are sent as name, size and type only. A body larger than `max_request_body_bytes` (16 KB) is sent as `{"truncated": true}`. Never sent for the paths and route names in `request_body_exclude`, by default the Fortify login, registration, password and two-factor routes. `ERROR_REPORTER_INCLUDE_REQUEST_BODY=false` turns it off.
- The exception code (`exception_code`, when not `0`) and the referrer (`referrer`, without query string, redacted) on server reports.
- The client address on server reports (`ip`), from `$request->ip()`. `ERROR_REPORTER_IP` chooses `masked` (default: IPv4 /24, IPv6 /48), `full` or `off`. `IpMode` holds the rule, so a collector can apply it too.
- `BrowserName::fromUserAgent()`, which turns a user agent into a browser family and major version such as `Safari 18`, for a collector to use on browser reports.
- `J87NL\ErrorReporter\Deploy\AppRelease`, the release helper for `deploy.php`, loadable without Laravel. See [Deploying](docs/deploying.md).
- Tests for the browser script, run with the test runner built into Node.js.

### Changed

- The browser script only sends a network error (Inertia's `HttpNetworkError`, a failed `fetch`, an XHR `Network Error`) when the same kind happens three times within 60 seconds in one page load. The `networkErrorThreshold` and `networkErrorWindowMs` options change this.

## [0.3.1] - 2026-10-23

### Changed

- Personal data redaction (e-mail addresses, IBANs, Dutch phone numbers) is now on by default. Set `ERROR_REPORTER_REDACT_PERSONAL_DATA=false` to turn it off. Errors whose message held such a value group under a new fingerprint once after the update.

## [0.3.0] - 2026-10-23

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

[0.5.0]: https://github.com/J87NL/laravel-error-reporter/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/J87NL/laravel-error-reporter/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/J87NL/laravel-error-reporter/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/J87NL/laravel-error-reporter/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/J87NL/laravel-error-reporter/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/J87NL/laravel-error-reporter/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/J87NL/laravel-error-reporter/releases/tag/v0.1.0
