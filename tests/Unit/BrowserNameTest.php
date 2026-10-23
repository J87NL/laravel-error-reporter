<?php

declare(strict_types=1);

use J87NL\ErrorReporter\BrowserName;

test('the browser family and major version are read from the user agent', function (string $userAgent, string $browser): void {
    expect(BrowserName::fromUserAgent($userAgent))->toBe($browser);
})->with([
    'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'Safari 18'],
    'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'Safari 18'],
    'Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'Chrome 141'],
    'Chrome on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.7390.41 Mobile/15E148 Safari/604.1', 'Chrome 141'],
    'Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox 132'],
    'Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.3537.57', 'Edge 141'],
    'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36', 'Samsung Internet 27'],
    'a crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Other'],
    'a command line client' => ['curl/8.5.0', 'Other'],
]);

test('a missing user agent gives no browser', function (mixed $userAgent): void {
    expect(BrowserName::fromUserAgent($userAgent))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
]);

test('a device whose name ends in bot is not taken for a crawler', function (): void {
    expect(BrowserName::fromUserAgent('Mozilla/5.0 (Linux; Android 13; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36'))->toBe('Chrome 141');
});
