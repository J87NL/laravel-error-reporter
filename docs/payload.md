# The Report

`J87NL\ErrorReporter\ErrorReport::fromThrowable()` turns an exception into a plain array. This array
is what the [transport](transports.md) receives and what the queued job posts to the collector.

## Fields

| Field | Type | Holds |
|---|---|---|
| `fingerprint` | string | A SHA-256 hash that groups the same error, see [Fingerprint](#fingerprint) |
| `exception_class` | string | The exception's class name |
| `exception_code` | int, string or null | The exception's `getCode()`, or `null` when it is `0`. A string code, such as a SQLSTATE, is cut to 50 characters |
| `message` | string | The message, [redacted](scrubbing.md#values) and cut to [`max_message_length`](configuration.md#limits) |
| `file` | string | The file the exception was thrown in, relative to the project root (absolute when outside it) |
| `line` | int | The line the exception was thrown on |
| `trace` | array | The stack frames, see [Trace](#trace) |
| `route` | string or null | The name of the current route |
| `method` | string or null | The HTTP method |
| `url` | string or null | The URL without the query string, with secret route parameters and [values](scrubbing.md#values) masked |
| `referrer` | string or null | The `Referer` header without its query string and hash, with [values](scrubbing.md#values) masked, cut to 2048 characters |
| `ip` | string or null | The client address, masked by default, see [Client address](#client-address) |
| `request_body` | array or null | The request input, scrubbed and redacted, see [Request body](#request-body) |
| `user_id` | string or null | The id of the user on the default guard, as a string |
| `environment` | string | The [environment](configuration.md#release-and-environment) |
| `release` | string or null | The [release](configuration.md#release-and-environment) |
| `context` | array | The exception's `context()`, scrubbed and redacted |
| `occurred_at` | string | The moment of the report, in ISO 8601 |

An example (the fingerprint is shortened):

```json
{
    "fingerprint": "3f1c...e9a0",
    "exception_class": "RuntimeException",
    "exception_code": null,
    "message": "Order 42 could not be found",
    "file": "app/Http/Controllers/OrderController.php",
    "line": 31,
    "trace": [
        {
            "file": "app/Http/Controllers/OrderController.php",
            "line": 31,
            "function": null,
            "class": null,
            "in_app": true
        }
    ],
    "route": "orders.show",
    "method": "GET",
    "url": "https://shop.test/orders/42",
    "referrer": "https://shop.test/orders",
    "ip": "203.0.113.0",
    "request_body": {
        "quantity": 2,
        "password": "[scrubbed]"
    },
    "user_id": "7",
    "environment": "production",
    "release": "abc1234",
    "context": [],
    "occurred_at": "2026-10-20T14:03:12+02:00"
}
```

## Trace

The first frame is the place the exception was thrown, with `function` and `class` set to `null`.
The frames after it come from the exception's stack trace. Each frame holds:

| Key | Holds |
|---|---|
| `file` | The file, relative to the project root, or `null`. A file outside the project root keeps its absolute path |
| `line` | The line, or `null` |
| `function` | The function or method name |
| `class` | The class name, or `null` |
| `in_app` | `true` when the file is outside any `vendor/` directory. A frame without a file is never in-app |

Function arguments are never sent. The trace is cut to [`max_frames`](configuration.md#limits)
frames, 30 by default.

## Message

First the [value rules](scrubbing.md#values) mask secrets, and personal data unless the app turns that off.
Then a message longer than `max_message_length` characters is cut to that length and ends with `...`, so
it can be up to three characters longer than the limit. A message that was longer than the limit
before masking also ends with `...`, even when masking made it shorter. Invalid UTF-8 is replaced so the report
is always valid JSON.

A `QueryException` gets a different message. It holds the SQLSTATE code, the connection name and the
SQL with its `?` placeholders:

```text
SQLSTATE[23000] (Connection: mysql, SQL: insert into users (email, password) values (?, ?))
```

The bindings are left out, and so is the driver's own message, which can contain the bound values.

## Context

When the exception has a `context()` method that returns an array, that array is sent. It is first
converted to plain JSON values: objects become what `json_encode()` makes of them, and values JSON
cannot hold, such as resources, become `null`. Then sensitive keys are masked and every string key and
value is redacted, see [Scrubbing & Privacy](scrubbing.md). When the masked context is larger than
[`max_context_bytes`](configuration.md#limits) as JSON, `{"truncated": true}` is sent instead.

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class PaymentFailed extends RuntimeException
{
    public function __construct(
        private readonly int $orderId,
        private readonly string $provider,
    ) {
        parent::__construct('The payment failed.');
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'order' => $this->orderId,
            'provider' => $this->provider,
        ];
    }
}
```

## Request body

The request input is sent, not the raw body: the form fields of a form post, or the decoded JSON of a
JSON request. The query string is not part of it, just as it is not part of the URL. Before it leaves
the app:

1. Every key that contains a [`scrub`](configuration.md#scrub) word is masked, at any depth, ignoring
   case.
2. The [value rules](scrubbing.md#values) mask secrets and personal data in the remaining string keys
   and values.
3. An uploaded file is sent as its name, size and type only, for example
   `{"name": "invoice.pdf", "size": 31, "mime": "application/pdf"}`. Its contents never leave the app.
4. A body larger than [`max_request_body_bytes`](configuration.md#request-body) (16 KB) as JSON, or
   nested deeper than eight levels, or that cannot be encoded as JSON, is sent as `{"truncated": true}`.

A request without input sends `null`. The body is never sent for the paths and route names in
[`request_body_exclude`](configuration.md#request-body): by default the login, registration,
password and two-factor routes of Laravel Fortify and the starter kits. Set
`ERROR_REPORTER_INCLUDE_REQUEST_BODY=false` to never send it.

## Client address

The address comes from `$request->ip()`, so it respects the trusted proxies of your app. With
[`ip`](configuration.md#client-address) set to:

| Mode | Sends |
|---|---|
| `masked` (default) | IPv4 with the last part zeroed (`203.0.113.77` → `203.0.113.0`), IPv6 cut to its first 48 bits (`2001:db8:85a3::`) |
| `full` | The address as it is |
| `off` | `null` |

An IPv4 address written as IPv6 (`::ffff:203.0.113.77`) is masked as IPv4. The collector can mask
further: it applies the mode set for the app there, when that is stricter. `J87NL\ErrorReporter\IpMode`
holds the rule, so a collector can use the same one.

## Fingerprint

The fingerprint is a SHA-256 hash of:

- the exception class;
- the file of the top in-app frame (or of the first frame, when no frame is in-app);
- the class and function of that frame;
- the normalized message.

The message is the redacted one, so two errors that differ only in a masked token, IBAN or phone
number get the same fingerprint.

Normalizing replaces the parts of a message that change between occurrences: UUIDs, e-mail
addresses, URLs, quoted strings, hex values and numbers. "Order 42 could not be found" and "Order 43
could not be found" get the same fingerprint.

The logic lives in `J87NL\ErrorReporter\Grouping`, with the static methods `fingerprint()`,
`normalizeMessage()` and `topInAppFrame()`:

```php
use J87NL\ErrorReporter\Grouping;

Grouping::normalizeMessage('Order 42 not found for jan@example.com');
// 'Order {number} not found for {email}'
```
