<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

class BrowserName
{
    public const string OTHER = 'Other';

    private const array FAMILIES = [
        'Edge' => '#\b(?:Edg|EdgA|EdgiOS)/(\d+)#',
        'Samsung Internet' => '#\bSamsungBrowser/(\d+)#',
        'Opera' => '#\b(?:OPR|OPiOS)/(\d+)#',
        'Firefox' => '#\b(?:Firefox|FxiOS)/(\d+)#',
        'Chrome' => '#\b(?:Chrome|CriOS)/(\d+)#',
        'Safari' => '#\bVersion/(\d+)(?:\.\d+)*(?: Mobile/\S+)? Safari/#',
    ];

    public static function fromUserAgent(mixed $userAgent): ?string
    {
        if (! is_string($userAgent) || trim($userAgent) === '') {
            return null;
        }

        if (preg_match('#bot/|crawler|spider|headless#i', $userAgent) === 1) {
            return self::OTHER;
        }

        foreach (self::FAMILIES as $family => $pattern) {
            if (preg_match($pattern, $userAgent, $match) === 1) {
                return sprintf('%s %d', $family, (int) $match[1]);
            }
        }

        return self::OTHER;
    }
}
