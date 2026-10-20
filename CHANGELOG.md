# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Reports every exception the Laravel handler reports to the J87 error collector, through a queued job with a 2-second timeout and a log fallback.
- Sends the fingerprint, class, message, file, line, a trimmed trace with in-app flags, route, method, URL without the query string, user id, environment, release and the exception's context.
- Masks secrets at any depth, and secret route parameters in the URL, before anything leaves the app.
- A browser script for Vue apps that reports `errorHandler`, `error` and `unhandledrejection` with the public browser key.
- An `ErrorTransport` contract, so a host can choose how reports leave.
