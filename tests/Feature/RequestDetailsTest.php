<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use J87NL\ErrorReporter\ErrorReporter;
use J87NL\ErrorReporter\IpMode;
use J87NL\ErrorReporter\Scrubber;

beforeEach(function (): void {
    Queue::fake();
    Route::post('orders', fn (): never => throw new RuntimeException('Boom'))->name('orders.store');
    Route::get('orders', fn (): never => throw new RuntimeException('Boom'))->name('orders.index');
    Route::post('login', fn (): never => throw new RuntimeException('Boom'))->name('login');
    Route::post('account/secret-change', fn (): never => throw new RuntimeException('Boom'))->name('password.update');
    Route::post('user/two-factor-authentication', fn (): never => throw new RuntimeException('Boom'))->name('two-factor.enable');
});

afterEach(function (): void {
    TrustProxies::flushState();
});

test('the request body is sent with sensitive keys masked at any depth and values redacted', function (): void {
    $this->post('/orders', [
        'product' => 'Lamp',
        'Password' => 'hunter2',
        'customer' => [
            'API_KEY' => 'abc',
            'note' => 'Mail me at jan@example.com',
        ],
    ]);

    expect(capturedReport()['request_body'])->toBe([
        'product' => 'Lamp',
        'Password' => Scrubber::MASK,
        'customer' => [
            'API_KEY' => Scrubber::MASK,
            'note' => sprintf('Mail me at %s', Scrubber::MASK),
        ],
    ]);
});

test('a JSON body is sent and the query string is not', function (): void {
    $this->postJson('/orders?coupon=SUMMER', [
        'quantity' => 2,
    ]);

    expect(capturedReport()['request_body'])->toBe([
        'quantity' => 2,
    ]);
});

test('a request without a body sends none', function (): void {
    $this->get('/orders?page=2');

    expect(capturedReport()['request_body'])->toBeNull();
});

test('an uploaded file is sent as its name, size and type only', function (): void {
    $this->post('/orders', [
        'invoice' => UploadedFile::fake()->createWithContent('invoice.pdf', 'PDF-CONTENT-THAT-MUST-NOT-LEAVE'),
        'photos' => [
            UploadedFile::fake()->create('front.jpg', 3, 'image/jpeg'),
        ],
    ]);

    $report = capturedReport();

    expect($report['request_body']['invoice'])->toBe([
        'name' => 'invoice.pdf',
        'size' => 31,
        'mime' => 'application/pdf',
    ])
        ->and($report['request_body']['photos'][0])->toBe([
            'name' => 'front.jpg',
            'size' => 3072,
            'mime' => 'image/jpeg',
        ])
        ->and(json_encode($report))->not->toContain('PDF-CONTENT');
});

test('a body larger than the limit is replaced by a truncated marker', function (): void {
    config()->set('error-reporter.max_request_body_bytes', 100);

    $this->post('/orders', [
        'notes' => str_repeat('a', 200),
    ]);

    expect(capturedReport()['request_body'])->toBe([
        'truncated' => true,
    ]);
});

test('the request body can be switched off', function (): void {
    config()->set('error-reporter.include_request_body', false);

    $this->post('/orders', [
        'product' => 'Lamp',
    ]);

    expect(capturedReport()['request_body'])->toBeNull();
});

test('the body of login, password and two-factor requests is never sent', function (string $path): void {
    $this->post($path, [
        'email' => 'jan@example.com',
        'code' => '123456',
    ]);

    expect(capturedReport()['request_body'])->toBeNull();
})->with([
    'a path on the list' => '/login',
    'a route name on the list' => '/account/secret-change',
    'a wildcard path' => '/user/two-factor-authentication',
]);

test('an app can put its own paths on the exclude list', function (): void {
    config()->set('error-reporter.request_body_exclude', [
        'orders',
    ]);

    $this->post('/orders', [
        'product' => 'Lamp',
    ]);

    expect(capturedReport()['request_body'])->toBeNull();
});

test('the exception code is sent when it is not zero', function (Throwable $exception, int|string|null $code): void {
    app(ErrorReporter::class)->report($exception);

    expect(capturedReport()['exception_code'])->toBe($code);
})->with([
    'a number' => [new RuntimeException('Boom', 42), 42],
    'zero' => [new RuntimeException('Boom'), null],
]);

test('the referrer is sent without its query string and with secrets redacted', function (): void {
    $this->withHeader('Referer', 'https://shop.test/invite/k3J9xQ2mZ8pL4vN7wR1tY6bH0cF5dG2s?email=jan@example.com#top')
        ->post('/orders');

    expect(capturedReport()['referrer'])->toBe(sprintf('https://shop.test/invite/%s', Scrubber::MASK));
});

test('the client address is masked by default', function (string $address, string $masked): void {
    $this->withServerVariables([
        'REMOTE_ADDR' => $address,
    ])->post('/orders');

    expect(capturedReport()['ip'])->toBe($masked);
})->with([
    'IPv4' => ['203.0.113.77', '203.0.113.0'],
    'IPv6' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3::'],
    'IPv4 inside IPv6' => ['::ffff:203.0.113.77', '203.0.113.0'],
]);

test('the client address can be sent in full or not at all', function (string $mode, ?string $expected): void {
    config()->set('error-reporter.ip', $mode);

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.77',
    ])->post('/orders');

    expect(capturedReport()['ip'])->toBe($expected);
})->with([
    'full' => ['full', '203.0.113.77'],
    'off' => ['off', null],
    'an unknown mode falls back to masked' => ['everything', '203.0.113.0'],
]);

test('the client address behind a trusted proxy is the forwarded one', function (): void {
    TrustProxies::at('10.0.0.1');
    config()->set('error-reporter.ip', 'full');

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
    ])->post('/orders');

    expect(capturedReport()['ip'])->toBe('203.0.113.77');
});

test('a report outside a request has no request details', function (): void {
    app(ErrorReporter::class)->report(new RuntimeException('Boom'));

    expect(capturedReport())
        ->referrer->toBeNull()
        ->ip->toBeNull()
        ->request_body->toBeNull();
});

test('masking an address twice gives the same address and an invalid one gives nothing', function (): void {
    expect(IpMode::Masked->apply('203.0.113.0'))->toBe('203.0.113.0')
        ->and(IpMode::Masked->apply('not an address'))->toBeNull()
        ->and(IpMode::Full->apply(null))->toBeNull()
        ->and(IpMode::fromConfig(' FULL '))->toBe(IpMode::Full);
});

test('a body nested deeper than eight levels is replaced by a truncated marker', function (): void {
    $this->postJson('/orders', [
        'a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => 'deep']]]]]]]],
    ]);

    expect(capturedReport()['request_body'])->toBe([
        'truncated' => true,
    ]);
});

test('the name of an uploaded file is redacted like any other value', function (): void {
    $this->post('/orders', [
        'attachment' => UploadedFile::fake()->create('jan@example.com.pdf', 1, 'application/pdf'),
    ]);

    expect(capturedReport()['request_body']['attachment']['name'])->toContain(Scrubber::MASK)->not->toContain('jan@');
});

test('a published config from before 0.4 masks the address and sends the body', function (): void {
    $config = config('error-reporter');

    unset($config['ip'], $config['include_request_body'], $config['max_request_body_bytes'], $config['request_body_exclude']);

    config()->set('error-reporter', $config);

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.77',
    ])->post('/orders', [
        'product' => 'Lamp',
    ]);

    expect(capturedReport())
        ->ip->toBe('203.0.113.0')
        ->request_body->toBe([
            'product' => 'Lamp',
        ]);
});

test('the body of a prefixed login route is never sent', function (): void {
    Route::post('nl/login', fn (): never => throw new RuntimeException('Boom'));

    $this->post('/nl/login', [
        'code' => '123456',
    ]);

    expect(capturedReport()['request_body'])->toBeNull();
});
