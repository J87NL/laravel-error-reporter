<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use J87NL\ErrorReporter\Deploy\AppRelease;

test('the release is the deployer release with the short commit hash', function (): void {
    expect(AppRelease::name('24', 'c402a44f00ba11c402a44f00ba11c402a44f00'))->toBe('24-c402a44');
});

test('a release from a dirty working tree is marked as dirty', function (): void {
    expect(AppRelease::name('24', 'c402a44f00ba11c402a44f00ba11c402a44f00', isDirty: true))->toBe('24-c402a44-dirty');
});

test('a release name that dotenv would misread is refused', function (string $releaseName): void {
    AppRelease::name($releaseName, 'c402a44f00ba11c402a44f00ba11c402a44f00');
})->with([
    'a space' => 'hot fix',
    'a quote' => 'hot"fix',
    'a comment' => 'hot#fix',
    'a variable' => '${APP_KEY}',
    'a newline' => "24\nAPP_DEBUG=true",
    'a trailing newline' => "24\n",
])->throws(InvalidArgumentException::class);

test('a commit that is not a git hash is refused', function (string $commit): void {
    AppRelease::name('24', $commit);
})->with([
    'empty' => '',
    'too short' => 'c402a4',
    'not hexadecimal' => 'main',
])->throws(InvalidArgumentException::class);

test('an invalid release is never written into the environment', function (string $release): void {
    AppRelease::writeInto("APP_ENV=production\n", $release);
})->with([
    'a space' => 'hot fix',
    'a trailing newline' => "24-c402a44\n",
])->throws(InvalidArgumentException::class);

test('the release line is appended to an environment without one', function (): void {
    expect(AppRelease::writeInto("APP_ENV=production\nAPP_KEY=base64:key", '24-c402a44'))
        ->toBe("APP_ENV=production\nAPP_KEY=base64:key\nAPP_RELEASE=24-c402a44\n");
});

test('an existing release line is replaced by exactly one new line', function (string $existingLine): void {
    $environment = AppRelease::writeInto(sprintf("APP_ENV=production\n%s\nDB_DATABASE=app\n", $existingLine), '24-c402a44');

    expect($environment)->toBe("APP_ENV=production\nDB_DATABASE=app\nAPP_RELEASE=24-c402a44\n")
        ->and(substr_count($environment, 'APP_RELEASE'))->toBe(1);
})->with([
    'empty' => 'APP_RELEASE=',
    'quoted' => 'APP_RELEASE="23-b13f2c1"',
    'exported' => 'export APP_RELEASE=23-b13f2c1',
    'indented with spaces around the equals sign' => '  APP_RELEASE = 23-b13f2c1',
]);

test('every earlier release line is removed when the secret holds several', function (): void {
    expect(AppRelease::writeInto("APP_RELEASE=1\nAPP_ENV=production\nexport APP_RELEASE=2\n", '24-c402a44'))
        ->toBe("APP_ENV=production\nAPP_RELEASE=24-c402a44\n");
});

test('an environment with CRLF line endings keeps its other values', function (): void {
    $environment = AppRelease::writeInto("APP_ENV=production\r\nAPP_RELEASE=23-b13f2c1\r\nDB_DATABASE=app\r\n", '24-c402a44');

    expect(Dotenv::parse($environment))->toBe([
        'APP_ENV' => 'production',
        'DB_DATABASE' => 'app',
        'APP_RELEASE' => '24-c402a44',
    ]);
});

test('variables that only resemble APP_RELEASE are kept', function (): void {
    $environment = AppRelease::writeInto("APP_RELEASE_NOTES=keep\nMY_APP_RELEASE=keep\n# APP_RELEASE=comment\nAPP_RELEASE=23-b13f2c1\n", '24-c402a44');

    expect($environment)->toBe("APP_RELEASE_NOTES=keep\nMY_APP_RELEASE=keep\n# APP_RELEASE=comment\nAPP_RELEASE=24-c402a44\n");
});

test('dotenv reads the written release, also when the secret held an empty one first', function (): void {
    $environment = AppRelease::writeInto("APP_ENV=production\nAPP_RELEASE=\n", AppRelease::name('24', 'c402a44f00ba11c402a44f00ba11c402a44f00'));

    expect(Dotenv::parse($environment)['APP_RELEASE'])->toBe('24-c402a44');
});

test('deploy.php can load the class from its file without the autoloader', function (): void {
    $script = sprintf(
        'require %s; echo J87NL\ErrorReporter\Deploy\AppRelease::name("24", "c402a44f00ba11c402a44f00ba11c402a44f00");',
        var_export(dirname(__DIR__, 2).'/src/Deploy/AppRelease.php', true),
    );

    exec(sprintf('%s -n -r %s', escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(implode('', $output))->toBe('24-c402a44');
});
