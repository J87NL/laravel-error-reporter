# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Reports every exception the Laravel handler reports to the J87 error collector, through a queued job with a 2-second timeout and a log fallback.
- Sends the fingerprint, class, message, file, line, a trimmed trace with in-app flags, route, method, URL without the query string, user id, environment, release and the exception's context.
- Masks secrets at any depth, secret route parameters in the URL and the bindings of a `QueryException` before anything leaves the app.
- Queues nothing while the collector is not configured, and never reports a failure of its own delivery job.
- A browser script for Vue apps that reports `errorHandler`, `error` and `unhandledrejection` with the public browser key, masking token-like path segments.
- An `ErrorTransport` contract, so a host can choose how reports leave.
- Replaces a scrubbed context larger than `max_context_bytes` by `{"truncated": true}` before it leaves the app.
- Logs the scrubbed report when the transport fails, so the report is not lost.
