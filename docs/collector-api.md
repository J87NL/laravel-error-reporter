# Collector API

The package reports errors to one endpoint on the collector. This page describes what that endpoint
accepts and how it answers. The heartbeat endpoint is described in [Heartbeats](heartbeats.md#delivery-and-failures). You only need it when you write your own client, a
[transport](transports.md) that posts elsewhere, or want to understand a fallback in your log.

```text
POST https://j87.nl/api/ingest/errors
```

## Authentication

The endpoint takes two kinds of keys. Both belong to one app on the collector.

| Source | Key | Sent as |
|---|---|---|
| Server | The server key (`ERROR_REPORTER_KEY`) | `Authorization: Bearer <key>` |
| Browser | The browser key (`ERROR_REPORTER_BROWSER_KEY`) | The `key` field in the body |

A request with a bearer token is treated as a server report. A request without one is treated as a
browser report, and must carry the browser key in its body.

## The body

The body is a JSON object. The collector reads the raw body, whatever its `Content-Type`. Server
reports are sent as `application/json`. The browser script sends the same JSON as `text/plain`, so
the browser makes no CORS preflight request.

The bodies the package sends are described in [The Report](payload.md) and
[What is sent](browser-errors.md#what-is-sent).

Only `exception_class` is required. The other fields are optional. The collector keeps what fits
and limits the rest. The two middle columns say whether the field is accepted from a server report
and from a browser report:

| Field | Accepted from server | Accepted from browser | Limit |
|---|---|---|---|
| `exception_class` | yes | yes | Required, a non-empty string. Cut to 250 characters |
| `exception_code` | yes | | An integer or a string, cut to 50 characters. `0` is dropped |
| `message` | yes | yes | Cut to 2000 characters, followed by `...` when cut |
| `trace` | yes | | The first 50 frames |
| `stack` | | yes | A stack string. The first 50 lines are parsed into frames |
| `file` | yes | yes | Cut to 255 characters. The browser script does not send it |
| `line` | yes | | A positive integer, otherwise dropped |
| `route` | yes | | Cut to 255 characters |
| `method` | yes | | Cut to 10 characters |
| `url` | yes | yes | Query string and hash removed, cut to 2048 characters |
| `referrer` | yes | | Query string and hash removed, redacted, cut to 2048 characters |
| `ip` | yes | | A valid IPv4 or IPv6 address, otherwise dropped. See below |
| `request_body` | yes | | An object. See below |
| `user_id` | yes | | Cut to 255 characters |
| `environment` | yes | yes | Cut to 50 characters |
| `release` | yes | yes | Cut to 100 characters |
| `context` | yes | yes | An object. See below |
| `occurred_at` | yes | yes | An ISO 8601 moment, not in the future and not older than 24 hours. See below |

A field without "yes" is ignored for that source. Strings are trimmed, and a string that is empty
after trimming counts as missing.

Some details:

- `exception_class` is checked before trimming. A value of only spaces passes the check and is then
  stored as `Error`.
- `context` is scrubbed first: the collector masks sensitive keys again. When the scrubbed context
  is larger than 8192 bytes as JSON, it is stored as `{"truncated": true}`. An empty or missing
  context is stored as `null`.
- `request_body` is scrubbed again, like `context`. When it is larger than 16384 bytes as JSON, it is
  stored as `{"truncated": true}`.
- The client address follows the address setting of the app on the collector: `masked` (the
  default for a new app), `full` or `off`. For a browser report the collector takes the address of
  the report request itself, behind its own trusted proxies. For a server report it takes the `ip`
  field and masks it when the app's setting is `masked`, or drops it when it is `off`. Masking an
  address that is already masked changes nothing, so the stricter of the two settings wins.
- For a browser report the collector stores the browser family and major version from the
  `User-Agent` header of the report request, such as `Safari 18`, `Chrome 141` or `Other`. The full
  user agent is not stored. Server reports have no browser.
- Fields the collector does not know are ignored, so a newer package can report to an older
  collector: the new fields are dropped until the collector is updated.
- `occurred_at` is not clamped: a missing or invalid value, a moment in the future, or one older
  than 24 hours is replaced by the time the collector received the report.
- The collector computes the fingerprint itself, from its own trimmed and cut copy of the report,
  with the rules described in [Fingerprint](payload.md#fingerprint). A `fingerprint` field in the
  body is not used. When the collector cuts the message or the trace, its hash can differ from the
  one the package computed.
- The body may be at most 262,144 bytes (256 KiB).

## Answers

Every answer from the ingest controller is JSON with a `status` field:

```json
{
    "status": "accepted"
}
```

The checks run in this order:

| Status code | `status` | Meaning |
|---|---|---|
| `413` | `too_large` | The body is larger than 256 KiB |
| `401` | `unknown_key` | The key is missing, unknown, or belongs to an app that is switched off |
| `429` | `too_many` | The app sent too many reports of this source this minute. The `Retry-After` header holds the seconds to wait |
| `422` | `invalid` | `exception_class` is missing, not a string, or an empty string. This request still counts towards the rate limit |
| `202` | `accepted` | The report was stored |

## Rate limits

- Per app and per source: 60 server reports and 10 browser reports per minute, by default.
- Per client address: 300 requests per minute over the whole endpoint. Above this the route's
  throttle answers `429` before the body is read. That answer is Laravel's default "Too Many
  Attempts." response, without a `status` field.

The queued job does not retry. A report refused with `429`, like any other 4xx or 5xx answer, is
written to the [log channel](configuration.md#log-channel) instead.
