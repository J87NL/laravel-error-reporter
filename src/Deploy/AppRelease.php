<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter\Deploy;

use InvalidArgumentException;

final class AppRelease
{
    /**
     * @throws InvalidArgumentException
     */
    public static function name(string $releaseName, string $commit, bool $isDirty = false): string
    {
        if (preg_match('/^[0-9a-f]{7,40}$/D', $commit) !== 1) {
            throw new InvalidArgumentException(sprintf('The commit "%s" is not a git hash, so APP_RELEASE cannot be set.', $commit));
        }

        $release = sprintf('%s-%s%s', $releaseName, substr($commit, 0, 7), $isDirty ? '-dirty' : '');

        self::ensureValid($release);

        return $release;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function writeInto(string $environment, string $release): string
    {
        self::ensureValid($release);

        $withoutRelease = preg_replace('/^[ \t]*(?:export[ \t]+)?APP_RELEASE[ \t]*=.*$\R?/m', '', $environment) ?? $environment;

        return sprintf("%s\nAPP_RELEASE=%s\n", rtrim($withoutRelease), $release);
    }

    private static function ensureValid(string $release): void
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $release) !== 1) {
            throw new InvalidArgumentException(sprintf('The release "%s" may only hold letters, digits, dots, dashes and underscores.', $release));
        }
    }
}
