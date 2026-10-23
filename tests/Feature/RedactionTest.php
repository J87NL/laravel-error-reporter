<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use J87NL\ErrorReporter\ErrorReport;
use J87NL\ErrorReporter\ErrorReporter;
use J87NL\ErrorReporter\Jobs\SendErrorReport;
use J87NL\ErrorReporter\Scrubber;
use J87NL\ErrorReporter\ValueRedactor;

class ExtendedErrorReport extends ErrorReport
{
    /**
     * @return array<string, mixed>
     */
    public function fromThrowable(Throwable $exception, ?Request $request, int|string|null $userId): array
    {
        return [
            ...parent::fromThrowable($exception, $request, $userId),
            'app' => 'extended',
        ];
    }
}

class ConstructedErrorReport extends ErrorReport
{
    public function __construct(Scrubber $scrubber)
    {
        parent::__construct($scrubber);
    }
}

class RecordingRedactor extends ValueRedactor
{
    /**
     * @var list<int>
     */
    public array $lengths = [];

    public function redactString(string $value): string
    {
        $this->lengths[] = mb_strlen($value);

        return parent::redactString($value);
    }
}

class ContextException extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>  $context
     */
    public function __construct(string $message, private readonly array $context)
    {
        parent::__construct($message);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}

function reportMessage(string $message): string
{
    Queue::fake();

    app(ErrorReporter::class)->report(new RuntimeException($message));

    return capturedReport()['message'];
}

/**
 * @param  array<array-key, mixed>  $context
 * @return array<array-key, mixed>
 */
function reportContext(array $context): array
{
    Queue::fake();

    app(ErrorReporter::class)->report(new ContextException('Failed', $context));

    return capturedReport()['context'];
}

test('secret values are masked in the message by default', function (string $message, string $expected): void {
    expect(reportMessage($message))->toBe($expected);
})->with([
    'bearer token' => [
        'Downstream refused Bearer hub-bearer-0123 for the call',
        'Downstream refused Bearer [scrubbed] for the call',
    ],
    'basic credentials' => [
        'Proxy refused Basic dXNlcjpwYXNz, giving up',
        'Proxy refused Basic [scrubbed], giving up',
    ],
    'secret query parameter' => [
        'Callback https://route.j87.nl/mcp?code=authcode-77&page=2 failed',
        'Callback https://route.j87.nl/mcp?code=[scrubbed]&page=2 failed',
    ],
    'query parameter that contains a scrub word' => [
        'Fetch https://api.test/v1?page=2&api_key=abc&limit=5 failed',
        'Fetch https://api.test/v1?page=2&api_key=[scrubbed]&limit=5 failed',
    ],
    'query parameter in camel case' => [
        'Fetch https://api.test/v1?accessToken=abc failed',
        'Fetch https://api.test/v1?accessToken=[scrubbed] failed',
    ],
    'parameter at the start of the value' => [
        'token=abc was refused',
        'token=[scrubbed] was refused',
    ],
    'parameter after a space' => [
        'Retry with token=abc failed',
        'Retry with token=[scrubbed] failed',
    ],
    'cookie string' => [
        'Cookie theme=dark; laravel_session=abc; XSRF-TOKEN=def',
        'Cookie theme=dark; laravel_session=[scrubbed]; XSRF-TOKEN=[scrubbed]',
    ],
    'parameter followed by punctuation' => [
        'Callback https://shop.test/cb?code=abc), retry. Then ?code=def.',
        'Callback https://shop.test/cb?code=[scrubbed]), retry. Then ?code=[scrubbed].',
    ],
    'signed url' => [
        'Link https://shop.test/unsubscribe/7?expires=1700000000&signature=f00ba4 expired',
        'Link https://shop.test/unsubscribe/7?expires=1700000000&signature=[scrubbed] expired',
    ],
    'quoted parameter' => [
        'Config token="abc123secret" rejected',
        'Config token="[scrubbed]" rejected',
    ],
    'parameters with joined names' => [
        'Fetch https://api.test/v1?sessionid=abc&apikey=def&authcode=ghi&PHPSESSID=jkl&page=2 failed',
        'Fetch https://api.test/v1?sessionid=[scrubbed]&apikey=[scrubbed]&authcode=[scrubbed]&PHPSESSID=[scrubbed]&page=2 failed',
    ],
    'password in a url without a user name' => [
        'Connection redis://:s3cretpass@127.0.0.1:6379 refused',
        'Connection redis://:[scrubbed]@127.0.0.1:6379 refused',
    ],
    'password with an at sign in a url' => [
        'Connection mysql://root:p@ss@db:3306/shop refused',
        'Connection mysql://root:[scrubbed]@db:3306/shop refused',
    ],
    'password in a connection url' => [
        'Connection mysql://root:hunter2@localhost:3306/shop refused',
        'Connection mysql://root:[scrubbed]@localhost:3306/shop refused',
    ],
    'base64 application key' => [
        'Decrypt with base64:Xk3+pQ/aZ9vLm2Nw8RtY4uIo0PsDfGhJ1kL5zXcV7bM= failed',
        'Decrypt with base64:[scrubbed] failed',
    ],
    'json web token' => [
        'Token eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiI0MiJ9.c2lnbmF0dXJl expired',
        'Token [scrubbed] expired',
    ],
    'opaque string of 32 or more characters' => [
        'Refresh def50200a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4 rejected',
        'Refresh [scrubbed] rejected',
    ],
    'opaque string with a prefix' => [
        'Key hub_live_51H8xKqLmNoPqRsTuVwXyZ0123456789 rejected',
        'Key [scrubbed] rejected',
    ],
    'credential with a lower case scheme' => [
        'Header authorization: bearer s3cr3tT0kenValue99 rejected',
        'Header authorization: bearer [scrubbed] rejected',
    ],
    'parameter whose last part is a short word' => [
        'Fetch https://api.test/v1?api_key=abc&clientCode=def failed',
        'Fetch https://api.test/v1?api_key=[scrubbed]&clientCode=[scrubbed] failed',
    ],
]);

test('ordinary values are left alone by the secret rules', function (string $message): void {
    expect(reportMessage($message))->toBe($message);
})->with([
    'a long class name without digits' => ['Class App\\Listeners\\RegisterTicketAvailabilityNotificationsListener not found'],
    'a long class name with digits in a namespace' => ['Class App\\Listeners\\Oauth2ClientCredentialsGrantedListener not found'],
    'a long class name with digits in a static call' => ['Call to Oauth2ClientCredentialsGrantedListener::handle() failed'],
    'a long method name with digits' => ['Call to undefined method App\\Models\\User::getOauth2ClientCredentialsTokenAttribute()'],
    'a long property name with digits' => ['Undefined property $this->oauth2ClientCredentialsTokenAttribute and $oauth2ClientCredentialsTokenAttribute'],
    'migration names with a digit in a word' => ['Migrations 2024_05_01_000000_add_s3_path_to_documents_table, 2019_08_19_000000_convert_tables_to_utf8mb4_unicode and 2024_05_01_000000_add_s3_and_utf8mb4_columns_table failed'],
    'a view name with a digit' => ['View [emails.order-confirmation-with-attachments2] not found.'],
    'a component with a digit' => ['Unable to locate file in Vite manifest: resources/js/pages/Dashboard/Widget2ForStatisticsAndLongerNames.vue.'],
    'slugs with short codes' => ['Page how-to-setup-2fa-with-php8-and-the-v2-api-in-mp4 not found'],
    'a short string with digits' => ['Order 42 failed for reference abc123secret'],
    'a harmless query parameter' => ['Fetch https://api.test/v1?page=2&sort=name failed'],
    'query parameters that only contain a secret word' => ['Fetch https://api.test/v1?author=jan&postcode=3011AB&keyword=tent failed'],
    'a uuid' => ['Order 9b2e1c4a-7d3f-4e8a-b5c6-0123456789ab not found'],
    'a uuid in an address' => ['GET https://shop.test/orders/9b2e1c4a-7d3f-4e8a-b5c6-0123456789ab failed'],
    'a migration name' => ['Migration 2019_12_14_000001_create_personal_access_tokens_table failed'],
    'a slug with a year' => ['Page summer-sale-2024-amsterdam-centraal-tickets not found'],
    'a compiled view' => ['Undefined variable $order (View: /var/www/storage/framework/views/0a1b2c3d4e5f60718293a4b5c6d7e8f901234567.php)'],
    'the word basic' => ['The basic plan has no Basic support for this account'],
    'the word bearer' => ['Bearer authentication failed for Bearer token'],
    'an e-mail address' => ['Confirmation to jan.jansen@example.com failed'],
    'a phone number' => ['Call 06-12345678 failed'],
    'codes and keys that are no secret' => ['Request failed with status_code=500 error_code=E42 postal_code=3011AB sort_key=name statusCode=404'],
    'a short word before the last part' => ['Fetch https://api.test/v1?code_style=psr12&key_order=asc failed'],
]);

test('personal data is only masked when the app opts in', function (string $message, string $expected): void {
    expect(reportMessage($message))->toBe($message);

    app()->forgetInstance(ErrorReporter::class);
    app()->forgetInstance(ValueRedactor::class);
    config()->set('error-reporter.redact.personal_data', true);

    expect(reportMessage($message))->toBe($expected);
})->with([
    'e-mail address' => [
        'Bevestiging aan piet@example.nl mislukt',
        'Bevestiging aan [scrubbed] mislukt',
    ],
    'e-mail address at the end of a sentence' => [
        'Bevestiging aan piet@mail.example.co.uk.',
        'Bevestiging aan [scrubbed].',
    ],
    'iban' => [
        'Refund to NL91ABNA0417164300 failed',
        'Refund to [scrubbed] failed',
    ],
    'iban followed by words in capitals' => [
        'IBAN NL91ABNA0417164300 IS NOT VALID FOR THIS',
        'IBAN [scrubbed] IS NOT VALID FOR THIS',
    ],
    'spaced iban followed by words in capitals' => [
        'IBAN BE68 5390 0754 7034 THIS IS NOT VALID',
        'IBAN [scrubbed] THIS IS NOT VALID',
    ],
    'spaced iban with a short last block' => [
        'Refund to NL91 ABNA 0417 1643 00 failed',
        'Refund to [scrubbed] failed',
    ],
    'mobile number with a zero in brackets' => [
        'SMS to +31 (0)6 12345678 failed',
        'SMS to [scrubbed] failed',
    ],
    'spaced foreign iban' => [
        'Refund to BE68 5390 0754 7034 failed',
        'Refund to [scrubbed] failed',
    ],
    'german iban' => [
        'Refund to DE89370400440532013000 failed',
        'Refund to [scrubbed] failed',
    ],
    'mobile number with a dash' => [
        'SMS to 06-12345678 failed',
        'SMS to [scrubbed] failed',
    ],
    'international mobile number' => [
        'SMS to +31 6 1234 5678 failed',
        'SMS to [scrubbed] failed',
    ],
    'mobile number with 0031' => [
        'SMS to 0031612345678 failed',
        'SMS to [scrubbed] failed',
    ],
    'landline number' => [
        'Call to 010-1234567 failed',
        'Call to [scrubbed] failed',
    ],
]);

test('personal data leaves numbers that are not phone numbers alone', function (string $message): void {
    config()->set('error-reporter.redact.personal_data', true);

    expect(reportMessage($message))->toBe($message);
})->with([
    'an eight digit number' => ['Order 12345678 failed'],
    'a number of eleven digits' => ['Batch 06123456789 failed'],
    'a dutch date with a time' => ['Import of 05-10-2026 12:30:00 failed'],
    'a decimal fraction' => ['Ratio 0.0123456789 out of range'],
    'an iban with a wrong checksum' => ['Account NL92ABNA0417164300 rejected'],
]);

test('personal data is masked while the secret rules are off', function (): void {
    config()->set('error-reporter.redact.secrets', false);
    config()->set('error-reporter.redact.personal_data', true);

    expect(reportMessage('Mail to jan@example.com with Bearer hub-bearer-0123 failed'))->toBe('Mail to [scrubbed] with Bearer hub-bearer-0123 failed');
});

test('values are masked in the message, the address and the context before anything leaves the app', function (): void {
    Queue::fake();
    config()->set('error-reporter.redact.personal_data', true);
    Route::get('orders/{reference}', function (): never {
        throw new ContextException('Mail aan jan.jansen@example.com (06-12345678, NL91ABNA0417164300, +31 6 1234 5678, BE68 5390 0754 7034) kon niet worden verstuurd', [
            'order' => 42,
            'password' => 'hunter2',
            'detail' => 'refresh def50200a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4 rejected',
            'nested' => [
                'header' => 'Bearer abc.def.ghi',
                'customer' => 'Jan, jan.jansen@example.com',
            ],
        ]);
    })->name('orders.show');

    $this->get('/orders/9f86d081884c7d659a2feaa0c55ad015');

    $report = capturedReport();

    expect($report['message'])->toBe('Mail aan [scrubbed] ([scrubbed], [scrubbed], [scrubbed], [scrubbed]) kon niet worden verstuurd')
        ->and($report['url'])->toBe('http://localhost/orders/[scrubbed]')
        ->and($report['context'])->toBe([
            'order' => 42,
            'password' => Scrubber::MASK,
            'detail' => 'refresh [scrubbed] rejected',
            'nested' => [
                'header' => 'Bearer [scrubbed]',
                'customer' => 'Jan, [scrubbed]',
            ],
        ]);
});

test('an e-mail address in the address is masked without the rest of the address', function (): void {
    Queue::fake();
    config()->set('error-reporter.redact.personal_data', true);
    Route::get('users/{email}/edit', fn (): never => throw new RuntimeException('Failed'));

    $this->get('/users/jan@example.com/edit');

    expect(capturedReport()['url'])->toBe('http://localhost/users/[scrubbed]/edit')
        ->and(reportMessage('Redirect https://site.nl/login?redirect=/home&email=jan@example.com failed'))->toBe('Redirect https://site.nl/login?redirect=/home&email=[scrubbed] failed');
});

test('context keys are masked as well, without one masked key overwriting another', function (): void {
    config()->set('error-reporter.redact.personal_data', true);

    expect(reportContext([
        'jan@example.com' => 'first',
        'piet@example.com' => 'second',
        'nested' => [
            'klaas@example.com' => 'third',
        ],
        'order' => 'kept',
    ]))->toBe([
        '[scrubbed]' => 'first',
        '[scrubbed]_2' => 'second',
        'nested' => [
            '[scrubbed]' => 'third',
        ],
        'order' => 'kept',
    ]);
});

test('context values that are not strings are kept as they are', function (): void {
    config()->set('error-reporter.redact.personal_data', true);

    expect(reportContext([
        'paid' => true,
        'refunded' => false,
        'amount' => 12.5,
        'order' => 1234567890,
        'phone' => 612345678,
        'note' => null,
        'lines' => [1, 2.5, null, 'jan@example.com'],
    ]))->toBe([
        'paid' => true,
        'refunded' => false,
        'amount' => 12.5,
        'order' => 1234567890,
        'phone' => 612345678,
        'note' => null,
        'lines' => [1, 2.5, null, Scrubber::MASK],
    ]);
});

test('errors that differ only in a masked value share a fingerprint', function (array $messages, bool $personalData): void {
    Queue::fake();
    config()->set('error-reporter.redact.personal_data', $personalData);

    foreach ($messages as $message) {
        app(ErrorReporter::class)->report(new RuntimeException($message));
    }

    $fingerprints = [];

    Queue::assertPushed(SendErrorReport::class, function (SendErrorReport $job) use (&$fingerprints): bool {
        $fingerprints[] = $job->report['fingerprint'];

        return true;
    });

    expect($fingerprints)->toHaveCount(2)
        ->and($fingerprints[0])->toBe($fingerprints[1]);
})->with([
    'json web tokens' => [
        [
            'Token eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiI0MiJ9.c2lnbmF0dXJl expired',
            'Token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI3In0.b3RoZXI expired',
        ],
        false,
    ],
    'bearer tokens' => [
        [
            'Refused Bearer abcDEF12',
            'Refused Bearer xyzUVW34',
        ],
        false,
    ],
    'opaque strings' => [
        [
            'Refresh def50200a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4 rejected',
            'Refresh zzz50200q1r2s3t4u5v60718293w4x5y6z7e8f90a1b2c3d4 rejected',
        ],
        false,
    ],
    'query parameters' => [
        [
            'Retry with token=abc failed',
            'Retry with token=xyz failed',
        ],
        false,
    ],
    'ibans' => [
        [
            'Refund to NL91ABNA0417164300 failed',
            'Refund to NL20INGB0001234567 failed',
        ],
        true,
    ],
    'e-mail addresses' => [
        [
            'Mail to jan@example.com failed',
            'Mail to piet@example.nl failed',
        ],
        true,
    ],
    'phone numbers' => [
        [
            'SMS to 06-12345678 failed',
            'SMS to +31 6 8765 4321 failed',
        ],
        true,
    ],
]);

test('a secret near the end of a long message is masked before the message is cut', function (): void {
    config()->set('error-reporter.max_message_length', 40);

    $message = reportMessage(sprintf('%s def50200a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4', str_repeat('x', 20)));

    expect($message)->toBe(sprintf('%s [scrubbed]', str_repeat('x', 20)));
});

test('a long message without whitespace keeps its start', function (): void {
    config()->set('error-reporter.max_message_length', 40);

    $message = reportMessage(str_repeat('x', 4000));

    expect($message)->toBe(sprintf('%s...', str_repeat('x', 40)));
});

test('the rules run in linear time on long input', function (string $input): void {
    $redactor = ValueRedactor::fromRules(true, true, ['token', ...ValueRedactor::DEFAULT_QUERY_PARAMETERS], []);

    $start = microtime(true);
    $redacted = $redactor->redactString($input);

    expect(microtime(true) - $start)->toBeLessThan(1.0)
        ->and($redacted)->not->toBe(Scrubber::MASK);
})->with([
    'a secret parameter of dots' => [sprintf('code=%sx', str_repeat('.', 100000))],
    'an e-mail address with many dots' => [sprintf('x@%s1', str_repeat('a.', 100000))],
    'many at signs before a domain' => [sprintf('%s x.nl', str_repeat('a@', 100000))],
    'many at signs and dots' => [str_repeat('a@a.', 50000)],
    'a long word with dots' => [str_repeat('a.', 100000)],
    'many json web token starts' => [str_repeat('eyJ', 70000)],
    'a long run of letters' => [str_repeat('a', 200000)],
    'many equals signs' => [str_repeat('a=', 100000)],
]);

test('a secret on the margin of a long message is dropped, not cut in half, and the message is marked as cut', function (): void {
    $secret = 'sk9Zq2LmX7vB4nR8tY1wE6uI3oP5aS0dF2gH';
    $message = 'Revoked: ';

    for ($index = 0; $index < 46; $index++) {
        $message .= sprintf('%s ', hash('sha256', (string) $index));
    }

    $message .= sprintf('%s %s tail', str_repeat('x', max(0, 3024 - mb_strlen($message) - 20)), $secret);

    $reported = reportMessage($message);

    expect($reported)->not->toContain(substr($secret, 0, 8))
        ->and($reported)->toEndWith('...')
        ->and(mb_strlen($reported))->toBeLessThanOrEqual(2003);
});

test('trailing punctuation after a long secret parameter is kept', function (): void {
    expect(reportMessage(sprintf('Retry with code=%sx. now', str_repeat('.', 3000))))->toBe('Retry with code=[scrubbed]. now');
});

test('a long message is cut to a bounded length before the rules run', function (): void {
    $redactor = new RecordingRedactor([]);
    app()->instance(ValueRedactor::class, $redactor);
    config()->set('error-reporter.max_message_length', 100);

    reportMessage(str_repeat('a@', 500000));

    expect(max($redactor->lengths))->toBeLessThanOrEqual(1124);
});

test('a context too large to send is dropped before the rules run', function (): void {
    $redactor = new RecordingRedactor([]);
    app()->instance(ValueRedactor::class, $redactor);

    expect(reportContext([
        'body' => str_repeat('a@', 50000),
    ]))->toBe([
        'truncated' => true,
    ])->and(max($redactor->lengths))->toBeLessThan(100000);
});

test('the secret rules can be switched off', function (): void {
    config()->set('error-reporter.redact.secrets', false);

    expect(reportMessage('Refused Bearer abcDEF12'))->toBe('Refused Bearer abcDEF12');
});

test('a published config without the redact section keeps the secret rules on', function (): void {
    config()->set('error-reporter.redact', null);

    expect(reportMessage('Refused Bearer abcDEF12'))->toBe('Refused Bearer [scrubbed]');
});

test('a pattern that masks every long run, letters only included, can be added', function (): void {
    config()->set('error-reporter.redact.patterns', [
        '/[A-Za-z0-9_-]{32,}+/',
    ]);

    expect(reportMessage('Token abcdefghijklmnopqrstuvwxyzABCDEFGH rejected'))->toBe('Token [scrubbed] rejected');
});

test('the config uses the default query parameter words of the redactor', function (): void {
    expect(config('error-reporter.redact.query_parameters'))->toBe(ValueRedactor::DEFAULT_QUERY_PARAMETERS);
});

test('the scrub words and the extra words both mark a query parameter as secret', function (): void {
    config()->set('error-reporter.scrub', [
        'pin',
    ]);
    config()->set('error-reporter.redact.query_parameters', [
        'ticket',
    ]);

    expect(reportMessage('Open https://shop.test/a?pin=1234&ticket_id=99&page=2 failed'))->toBe('Open https://shop.test/a?pin=[scrubbed]&ticket_id=[scrubbed]&page=2 failed');
});

test('extra patterns are masked and an invalid pattern is skipped with one warning', function (): void {
    Log::spy();
    config()->set('error-reporter.redact.patterns', [
        '/\bORD-\d{6}\b/',
        '/(unclosed/',
    ]);

    expect(reportMessage('Order ORD-123456 failed'))->toBe('Order [scrubbed] failed')
        ->and(reportMessage('Order ORD-654321 failed'))->toBe('Order [scrubbed] failed');

    Log::shouldHaveReceived('warning')
        ->with('The error reporter skipped a redact pattern that is not a valid regular expression.', [
            'pattern' => '/(unclosed/',
        ])
        ->once();
});

test('a value the rules cannot process is masked as a whole', function (): void {
    config()->set('error-reporter.redact.patterns', [
        '/(a+)+$/',
    ]);

    expect(reportMessage(sprintf('Refused %sb', str_repeat('a', 40))))->toBe(Scrubber::MASK);
});

test('the redactor can be used on its own, for example by a collector', function (): void {
    config()->set('error-reporter.redact.personal_data', true);

    expect(app(ValueRedactor::class)->redact([
        'message' => 'Token eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiI0MiJ9.c2lnbmF0dXJl for jan@example.com',
        'line' => 12,
        'stack' => [
            'at https://shop.test/reset/def50200a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4',
        ],
    ]))->toBe([
        'message' => 'Token [scrubbed] for [scrubbed]',
        'line' => 12,
        'stack' => [
            'at https://shop.test/reset/[scrubbed]',
        ],
    ]);
});

test('a report class an app extended before value redaction existed still resolves and redacts', function (): void {
    Queue::fake();
    app()->bind(ErrorReport::class, ExtendedErrorReport::class);

    app(ErrorReporter::class)->report(new RuntimeException('Refused Bearer abcDEF12'));

    expect(capturedReport())
        ->message->toBe('Refused Bearer [scrubbed]')
        ->app->toBe('extended');
});

test('a report built with only a scrubber still redacts', function (ErrorReport $report): void {
    expect($report->fromThrowable(new RuntimeException('Refused Bearer abcDEF12'), null, null)['message'])->toBe('Refused Bearer [scrubbed]');
})->with([
    'constructed directly' => [fn (): ErrorReport => new ErrorReport(app(Scrubber::class))],
    'a subclass that calls the parent constructor' => [fn (): ErrorReport => new ConstructedErrorReport(app(Scrubber::class))],
]);
