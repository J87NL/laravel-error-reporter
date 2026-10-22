# Scrubbing & Privacy

Secrets are masked in your app, before anything is queued or sent.

## Context keys

Every key in the exception's [context](payload.md#context) that contains one of the words in
[`scrub`](configuration.md#scrub) is replaced by `[scrubbed]`, at any depth. The default words are
`password`, `token`, `secret`, `authorization`, `cookie` and `key`.

```php
// context() returns
[
    'order' => 42,
    'password' => 'hunter2',
    'api_key' => 'abc',
    'nested' => [
        'Authorization' => 'Bearer xyz',
        'step' => 'payment',
    ],
]

// the report holds
[
    'order' => 42,
    'password' => '[scrubbed]',
    'api_key' => '[scrubbed]',
    'nested' => [
        'Authorization' => '[scrubbed]',
        'step' => 'payment',
    ],
]
```

The match ignores case and matches parts of words. `key` therefore also masks keys such as
`monkey` and `primary_key`. This rule only looks at keys. A secret stored under a harmless key is
caught by [value redaction](#values) when it matches one of its rules.

To change the list, publish the config and edit `scrub`. The mask itself is the constant
`J87NL\ErrorReporter\Scrubber::MASK`.

## Route parameters

A route parameter whose name matches the same list is masked in the reported URL. Every path segment
that equals its value is replaced as a whole:

```php
Route::get('reset-password/{token}', ...);

// https://example.com/reset-password/abc123secret
// is reported as
// https://example.com/reset-password/[scrubbed]
```

The value is the raw one from the URL, so a parameter that route model binding turns into a model
is masked as well.

## Values

Keys only catch a secret that sits under a telling name. Value redaction also looks at the values
themselves: the message, the URL and every string in the context, at any depth. A match is replaced
by `[scrubbed]`. It runs before the message is cut to
[`max_message_length`](configuration.md#limits), so a cut never leaves half a token, and before the
[fingerprint](payload.md#fingerprint) is computed, so errors that differ only in a masked value are
grouped together.

There are two sets of rules, configured under [`redact`](configuration.md#redact).

### Secrets (on by default)

| Rule | Example | Reported as |
|---|---|---|
| A `Bearer` or `Basic` value | `Bearer hub-0123` | `Bearer [scrubbed]` |
| A query parameter whose name contains a [`scrub`](configuration.md#scrub) word or a word in `redact.query_parameters` (`code`, `auth`, `session`, `verifier` and `signature` by default) | `?code=abc&page=2` | `?code=[scrubbed]&page=2` |
| A JSON Web Token | `eyJhbGciOi...` | `[scrubbed]` |
| A run of 32 or more letters, digits, `-` and `_` that contains at least one digit | `def50200a1b2c3d4e5f6...` | `[scrubbed]` |

The last rule catches API keys, refresh tokens, hashes and UUIDs. It leaves a long class name or a
snake case word without digits alone, so `RegisterTicketAvailabilityNotificationsListener` is still
readable. A random token of 32 characters without a single digit is rare, but it is not caught.

The `Bearer` and `Basic` rule masks the next word whatever it is, so `Bearer token missing` is
reported as `Bearer [scrubbed] missing`.

### Personal data (opt-in)

| Rule | Example |
|---|---|
| E-mail addresses | `jan.jansen@example.com` |
| IBANs, also spaced and from other countries | `NL91ABNA0417164300`, `BE68 5390 0754 7034` |
| Dutch phone numbers, mobile and landline, with `0`, `+31` or `0031`, with or without spaces and dashes | `06-12345678`, `+31 6 1234 5678`, `010-1234567` |

Turn these on for an app that handles customer data:

```dotenv
ERROR_REPORTER_REDACT_PERSONAL_DATA=true
```

The phone rule masks any ten digits that start with `0`, `+31` or `0031`, such as an order number
`0612345678`. The IBAN rule masks any two capitals followed by two digits and ten or more capitals
and digits.

### Your own patterns

Add regular expressions under `redact.patterns`. Every match is replaced by `[scrubbed]`, whether the
secret and personal data rules are on or off. A pattern that is not a valid regular expression is
skipped.

```php
'redact' => [
    // ...
    'patterns' => [
        '/\bORD-\d{6}\b/',
    ],
],
```

When a value cannot be processed, for example because a pattern backtracks too much, the whole value
is replaced by `[scrubbed]`. Context keys are never changed by value redaction.

### Using the rules elsewhere

The rules live in `J87NL\ErrorReporter\ValueRedactor`, bound in the container with the rules from
your config. A collector can apply them to what it receives:

```php
use J87NL\ErrorReporter\ValueRedactor;

app(ValueRedactor::class)->redact($payload);
```

`redact()` takes a string or an array of any depth and returns it with every string redacted.

## What is not scrubbed

The stack trace, the file and the user id are sent as they are. A secret that matches none of the
rules above is sent as is, so do not put secrets in exception messages. A `QueryException` leaves
out its bindings, see [Message](payload.md#message).

## What is never sent

- The query string of the URL.
- Request input, headers and cookies.
- Function arguments in the stack trace.
- The bindings of a `QueryException`, and the driver message that can contain them. See
  [Message](payload.md#message).

## Browser errors

The browser script masks every path segment of 32 or more letters, digits, `-` and `_`, such as a
reset token, and never sends the query string or the hash. See
[What is sent](browser-errors.md#what-is-sent). It does not apply the value rules to the message or
the stack; a collector can do that with the [`ValueRedactor`](#using-the-rules-elsewhere).

## The keys

The server key (`ERROR_REPORTER_KEY`) is secret. It is only used by the queued job on the server and
is never shared with the page.

The browser key (`ERROR_REPORTER_BROWSER_KEY`) is public by design. `browserConfig()` shares it with
every page, so anyone can read it. It can only be used to send browser errors to your app on the
collector.
