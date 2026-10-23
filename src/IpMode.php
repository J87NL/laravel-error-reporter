<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

enum IpMode: string
{
    case Masked = 'masked';
    case Full = 'full';
    case Off = 'off';

    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? self::tryFrom(strtolower(trim($value))) ?? self::Masked : self::Masked;
    }

    public function apply(mixed $ip): ?string
    {
        if ($this === self::Off || ! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if ($this === self::Full) {
            return $ip;
        }

        return self::mask($ip);
    }

    public static function mask(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        if (! is_string($packed)) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, sprintf('%s%s', str_repeat("\0", 10), "\xff\xff"))) {
            $packed = substr($packed, 12);
        }

        $kept = strlen($packed) === 4 ? 3 : 6;
        $masked = inet_ntop(sprintf('%s%s', substr($packed, 0, $kept), str_repeat("\0", strlen($packed) - $kept)));

        return is_string($masked) ? $masked : null;
    }
}
