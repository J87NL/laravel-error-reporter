# Deploying

The collector ties every error to a release, so you can see which deploy brought an error in.
`APP_RELEASE` holds that release. The package ships a small helper to set it from a
[Deployer](https://deployer.org) script.

## The AppRelease helper

`J87NL\ErrorReporter\Deploy\AppRelease` is plain PHP without dependencies, so `deploy.php` can load it
without booting Laravel and without the Composer autoloader:

```php
require_once __DIR__.'/vendor/j87nl/laravel-error-reporter/src/Deploy/AppRelease.php';
```

The deploy needs the vendor directory to be installed first, which a Deployer setup that uploads the
project with `rsync` after `composer install` already has.

| Method | Does |
|---|---|
| `AppRelease::name(string $releaseName, string $commit, bool $isDirty = false): string` | Returns `<release name>-<short sha>`, with `-dirty` added for a working tree with changes |
| `AppRelease::writeInto(string $environment, string $release): string` | Removes every `APP_RELEASE` line from the `.env` contents and appends one with the new release |

Both throw an `InvalidArgumentException` when the commit is not a git hash or the release holds
anything but letters, digits, dots, dashes and underscores, so a value that dotenv would misread never
reaches the `.env` file. `writeInto()` keeps CRLF line endings, `export APP_RELEASE=` lines are
replaced too, and variables that only resemble it, such as `APP_RELEASE_NOTES`, are kept.

## In deploy.php

This task writes the `.env` from a `DOT_ENV` secret and adds the release:

```php
<?php

declare(strict_types=1);

namespace Deployer;

use InvalidArgumentException;
use J87NL\ErrorReporter\Deploy\AppRelease;

require 'recipe/laravel.php';
require_once __DIR__.'/vendor/j87nl/laravel-error-reporter/src/Deploy/AppRelease.php';

desc('Write the .env from the DOT_ENV secret, with the release');
task('deploy:secrets', function (): void {
    $environment = getenv('DOT_ENV');

    if (! is_string($environment) || $environment === '') {
        throw error('DOT_ENV is empty.');
    }

    $release = appRelease();

    file_put_contents(__DIR__.'/.env', AppRelease::writeInto($environment, $release));
    upload('.env', get('deploy_path').'/shared');
    info(sprintf('APP_RELEASE is %s', $release));
});

function appRelease(): string
{
    $commit = getenv('GITHUB_SHA');
    $isDirty = false;

    if (! is_string($commit) || $commit === '') {
        $commit = runLocally('git rev-parse HEAD', [
            'cwd' => __DIR__,
        ]);
        $isDirty = runLocally('git status --porcelain', [
            'cwd' => __DIR__,
        ]) !== '';
    }

    try {
        return AppRelease::name((string) get('release_name'), trim($commit), $isDirty);
    } catch (InvalidArgumentException $exception) {
        throw error($exception->getMessage());
    }
}
```

On GitHub Actions `GITHUB_SHA` holds the commit. A deploy from a laptop reads it from git and marks a
working tree with changes as dirty, for example `24-c402a44-dirty`.

## Moving from your own copy

Apps that kept their own `App\Support\AppRelease` can delete it and its tests. In `deploy.php`,
replace the `use` line with `use J87NL\ErrorReporter\Deploy\AppRelease;` and the `require_once` with
the path above. The API is the same.
