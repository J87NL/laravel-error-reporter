<?php

declare(strict_types=1);

use Illuminate\Http\Request;
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

function redactedReport(): array
{
    $report = null;

    Queue::assertPushed(SendErrorReport::class, function (SendErrorReport $job) use (&$report): bool {
        $report = $job->report;

        return true;
    });

    return $report;
}

function reportMessage(string $message): string
{
    Queue::fake();

    app(ErrorReporter::class)->report(new RuntimeException($message));

    return redactedReport()['message'];
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
    'signed url' => [
        'Link https://shop.test/unsubscribe/7?expires=1700000000&signature=f00ba4 expired',
        'Link https://shop.test/unsubscribe/7?expires=1700000000&signature=[scrubbed] expired',
    ],
    'json web token' => [
        'Token eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiI0MiJ9.c2lnbmF0dXJl expired',
        'Token [scrubbed] expired',
    ],
    'opaque string of 32 or more characters' => [
        'Refresh def50200a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4 rejected',
        'Refresh [scrubbed] rejected',
    ],
]);

test('ordinary values are left alone by the secret rules', function (string $message): void {
    expect(reportMessage($message))->toBe($message);
})->with([
    'a long class name without digits' => ['Class App\\Listeners\\RegisterTicketAvailabilityNotificationsListener not found'],
    'a short string with digits' => ['Order 42 failed for reference abc123secret'],
    'a harmless query parameter' => ['Fetch https://api.test/v1?page=2&sort=name failed'],
    'an e-mail address' => ['Confirmation to jan.jansen@example.com failed'],
    'a phone number' => ['Call 06-12345678 failed'],
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
    'iban' => [
        'Refund to NL91ABNA0417164300 failed',
        'Refund to [scrubbed] failed',
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
]);

test('values are masked in the message, the address and the context before anything leaves the app', function (): void {
    Queue::fake();
    config()->set('error-reporter.redact.personal_data', true);
    Route::get('orders/{reference}', function (): never {
        throw new class('Mail aan jan.jansen@example.com (06-12345678, NL91ABNA0417164300, +31 6 1234 5678, BE68 5390 0754 7034) kon niet worden verstuurd') extends RuntimeException
        {
            /**
             * @return array<string, mixed>
             */
            public function context(): array
            {
                return [
                    'order' => 42,
                    'password' => 'hunter2',
                    'detail' => 'refresh def50200a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4 rejected',
                    'nested' => [
                        'header' => 'Bearer abc.def',
                        'customer' => 'Jan, jan.jansen@example.com',
                        'jan@example.com' => 'kept key',
                    ],
                ];
            }
        };
    })->name('orders.show');

    $this->get('/orders/0123456789abcdef0123456789abcdef');

    $report = redactedReport();

    expect($report['message'])->toBe('Mail aan [scrubbed] ([scrubbed], [scrubbed], [scrubbed], [scrubbed]) kon niet worden verstuurd')
        ->and($report['url'])->toBe('http://localhost/orders/[scrubbed]')
        ->and($report['context'])->toBe([
            'order' => 42,
            'password' => Scrubber::MASK,
            'detail' => 'refresh [scrubbed] rejected',
            'nested' => [
                'header' => 'Bearer [scrubbed]',
                'customer' => 'Jan, [scrubbed]',
                'jan@example.com' => 'kept key',
            ],
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
            'Refused Bearer abcDEF',
            'Refused Bearer xyzUVW',
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
]);

test('a secret near the end of a long message is masked before the message is cut', function (): void {
    config()->set('error-reporter.max_message_length', 40);

    $message = reportMessage(sprintf('%s def50200a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4', str_repeat('x', 20)));

    expect($message)->toBe(sprintf('%s [scrubbed]', str_repeat('x', 20)));
});

test('the secret rules can be switched off', function (): void {
    config()->set('error-reporter.redact.secrets', false);

    expect(reportMessage('Refused Bearer abcDEF'))->toBe('Refused Bearer abcDEF');
});

test('a published config without the redact section keeps the secret rules on', function (): void {
    config()->set('error-reporter.redact', null);

    expect(reportMessage('Refused Bearer abcDEF'))->toBe('Refused Bearer [scrubbed]');
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

test('extra patterns are masked and an invalid pattern is skipped', function (): void {
    config()->set('error-reporter.redact.patterns', [
        '/\bORD-\d{6}\b/',
        '/(unclosed/',
    ]);

    expect(reportMessage('Order ORD-123456 failed'))->toBe('Order [scrubbed] failed');
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

    app(ErrorReporter::class)->report(new RuntimeException('Refused Bearer abcDEF'));

    expect(redactedReport())
        ->message->toBe('Refused Bearer [scrubbed]')
        ->app->toBe('extended');
});
