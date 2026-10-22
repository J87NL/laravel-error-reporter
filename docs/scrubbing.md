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
themselves: the message, the URL and every string key and value in the context, at any depth. A
match is replaced by `[scrubbed]`. When two masked context keys would collide, the second becomes
`[scrubbed]_2`. It runs before the message is cut to
[`max_message_length`](configuration.md#limits), so a secret is masked before the cut instead of being
sent in part, and before the
[fingerprint](payload.md#fingerprint) is computed, so errors that differ only in a masked value are
grouped together.

There are two sets of rules, configured under [`redact`](configuration.md#redact).

### Secrets (on by default)

| Rule | Example | Reported as |
|---|---|---|
| A `Bearer` or `Basic` credential | `Bearer hub-bearer-0123` | `Bearer [scrubbed]` |
| A secret parameter in a query string, a cookie string or plain text | `?code=abc&page=2` | `?code=[scrubbed]&page=2` |
| The password in a URL, also without a user name or with an `@` in it | `mysql://root:hunter2@db` | `mysql://root:[scrubbed]@db` |
| A Laravel `base64:` key | `base64:Xk3+pQ/aZ9...=` | `base64:[scrubbed]` |
| A JSON Web Token | `eyJhbGciOi...` | `[scrubbed]` |
| An opaque string, see below | `def50200a1b2c3d4e5f6...` | `[scrubbed]` |

`Bearer` and `Basic` are matched with a capital only, and only when the value that follows has at
least eight characters and looks like a credential: it has a digit, a symbol such as `-`, `.` or `=`,
or a lower case letter followed by a capital. `The basic plan` and `Bearer token missing` are left
alone.

A parameter is secret when one of the parts of its name is a [`scrub`](configuration.md#scrub) word or
a word in [`redact.query_parameters`](configuration.md#redact) (`code`, `auth`, `session`,
`verifier`, `signature`, `apikey`, `sessionid`, `sessid` and `authcode` by default). Parts are split
on `_`, `-`, `.` and capitals, so `api_key`, `XSRF-TOKEN` and `accessToken` are secret, while
`author`, `postcode` and `keyword` are not. A word of five or more letters also matches at the end of
a part, so `accesstoken` and `PHPSESSID` are secret too. The parameter can start the string or follow
`?`, `&`, `;` or a space, its value may be quoted (`token="..."`), and the value stops at `&`, `#`,
`;`, a space, a quote or a bracket, and leaves trailing `.`, `!`, `?` and `:` alone. A value after a
colon, such as `api_key: abc` or JSON's `"token":"abc"`, is not a parameter; the
[`scrub`](configuration.md#scrub) words catch it as a context key.

An opaque string is a run of 32 or more letters, digits, `-` and `_` in which one of the parts between
`-` and `_` looks random: it has a digit, and it switches between letters and digits at least four
times, or it has 16 or more characters and changes between lower case, capitals and digits at every
other character or more often. It catches API keys, refresh tokens and hashes, such as a git SHA. It
leaves alone:

- a UUID, such as `9b2e1c4a-7d3f-4e8a-b5c6-0123456789ab`;
- words and numbers joined by `-` or `_`, also with short codes such as `s3`, `utf8mb4`, `2fa` or
  `v2`, such as the migration `2019_08_19_000000_convert_tables_to_utf8mb4_unicode` or the slug
  `summer-sale-2024-amsterdam-centraal`;
- a camel case name with a digit or two, such as `Widget2ForStatisticsAndLongerNames`;
- a class name, after a `\` or before a `\` or `::`, such as `App\Listeners\Oauth2ClientCredentialsListener`;
- a method, property or variable name, after `::`, `->` or `$`;
- a compiled view, the hash before `.php` in `storage/framework/views`.

What it misses: a token of only letters, a token with few switches between letters and digits (a
random token of 32 characters has about eight, so this is rare), a token of letters and digits in
separate parts, and a standard
base64 secret with `+`, `/` or `=` outside a `base64:` key, which the rule only masks in pieces. Add a
[pattern](#your-own-patterns) for those when your app has them.

### Personal data (opt-in)

| Rule | Example |
|---|---|
| E-mail addresses | `jan.jansen@example.com` |
| IBANs, also spaced in groups of four and from other countries | `NL91ABNA0417164300`, `BE68 5390 0754 7034` |
| Dutch phone numbers, mobile and landline, with `0`, `+31`, `+31 (0)` or `0031`, with or without spaces and dashes | `06-12345678`, `+31 (0)6 1234 5678`, `010-1234567` |

Turn these on for an app that handles customer data:

```dotenv
ERROR_REPORTER_REDACT_PERSONAL_DATA=true
```

The phone rule masks any ten digits that start with `0`, `+31` or `0031`, such as an order number
`0612345678`. The IBAN rule masks any two capitals followed by two digits and ten to thirty capitals
and digits, or by groups of four of which every group after the first has a digit. Words in capitals
after an IBAN are left alone. An IBAN in lower case, such as `nl91abna0417164300`, is not masked.
An e-mail address in a URL is masked on its own, so `/users/jan@example.com/edit` is reported as
`/users/[scrubbed]/edit`.

### Your own patterns

Add regular expressions under `redact.patterns`. Every match is replaced by `[scrubbed]`, whether the
secret and personal data rules are on or off. A pattern that is not a valid regular expression is
skipped, with one warning in the log when the rules are loaded.

```php
'redact' => [
    // ...
    'patterns' => [
        '/\bORD-\d{6}\b/',
    ],
],
```

When a value cannot be processed, for example because a pattern backtracks too much, the whole value
is replaced by `[scrubbed]`. The built-in rules run in linear time. Before they run, a message longer
than [`max_message_length`](configuration.md#limits) plus 1024 characters is cut to that length, and
the word the cut ends in is dropped, so no part of a secret slips through. A context larger than
[`max_context_bytes`](configuration.md#limits) is dropped, so a huge value never reaches the rules.

### Using the rules elsewhere

The rules live in `J87NL\ErrorReporter\ValueRedactor`, bound in the container with the rules from
your config. A collector can apply them to what it receives:

```php
use J87NL\ErrorReporter\ValueRedactor;

app(ValueRedactor::class)->redact($payload);
```

`redact()` takes a string or an array of any depth and returns it with every string key and value
redacted.

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
