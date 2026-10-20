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
`monkey` and `primary_key`. Only keys are checked, never values: a secret stored under a harmless
key is sent as is.

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

## What is not scrubbed

Scrubbing only looks at context keys and route parameter names. The exception message, the stack
trace and the user id are sent as they are. Do not put secrets in exception messages. The one
exception is a `QueryException`, whose bindings are left out, see [Message](payload.md#message).

## What is never sent

- The query string of the URL.
- Request input, headers and cookies.
- Function arguments in the stack trace.
- The bindings of a `QueryException`, and the driver message that can contain them. See
  [Message](payload.md#message).

## Browser errors

The browser script masks every path segment of 32 or more letters, digits, `-` and `_`, such as a
reset token, and never sends the query string or the hash. See
[What is sent](browser-errors.md#what-is-sent).

## The keys

The server key (`ERROR_REPORTER_KEY`) is secret. It is only used by the queued job on the server and
is never shared with the page.

The browser key (`ERROR_REPORTER_BROWSER_KEY`) is public by design. `browserConfig()` shares it with
every page, so anyone can read it. It can only be used to send browser errors to your app on the
collector.
