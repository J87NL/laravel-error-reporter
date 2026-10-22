<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Closure;

class ValueRedactor
{
    /**
     * Words that mark a query parameter as secret, on top of the scrub words.
     *
     * @var list<string>
     */
    public const array DEFAULT_QUERY_PARAMETERS = [
        'code',
        'auth',
        'session',
        'verifier',
        'signature',
        'apikey',
        'sessionid',
        'sessid',
        'authcode',
    ];

    private const string CREDENTIAL_PATTERN = '/\b(Bearer|Basic)(\s+)([A-Za-z0-9._~+\/=-]{8,}+)/';

    private const string URL_PASSWORD_PATTERN = '/(?<![a-z0-9+.-])([a-z][a-z0-9+.-]*+:\/\/[^\s:\/@]*+:)[^\s\/@]*+(?:@[^\s\/@]*+)*@(?=[^\s\/@]+)/i';

    private const string BASE64_KEY_PATTERN = '/\bbase64:[A-Za-z0-9+\/]{16,}+={0,2}/';

    private const string QUERY_PARAMETER_PATTERN = '/(?<=^|[?&;\s])([A-Za-z0-9_.\[\]-]{1,100}+=["\']?)([^&#;\s"\'<>(),]++)/';

    private const string JWT_PATTERN = '/(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]++\.[A-Za-z0-9_-]++\.[A-Za-z0-9_-]*+/';

    private const string OPAQUE_PATTERN = '/(?<![A-Za-z0-9_\\\\-])(?<!::|->|\$)[A-Za-z0-9_-]{32,}+(?!\.php\b|\\\\|::)/';

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const array PERSONAL_DATA_PATTERNS = [
        '/(?<![^\s<>"\'(),;:\[\]@\/?&=#])[^\s<>"\'(),;:\[\]@\/?&=#]++@(?:[A-Za-z0-9-]++\.){1,20}[a-z]{2,}+/i',
        '/\b[A-Z]{2}\d{2}(?:[A-Z0-9]{10,30}+|(?:\s[A-Z0-9]{4})(?:\s(?=[A-Z]{0,3}\d)[A-Z0-9]{4}){1,6}(?:\s(?=[A-Z]{0,2}\d)[A-Z0-9]{1,3})?)\b/',
        '/(?<![\w+])(?:(?:\+31|0031)[\s-]?(?:\(0\)[\s-]?)?|0[\s-]?)\d(?:[\s-]?\d){8}(?!\d)/',
    ];

    /**
     * @param  list<array{string, string|Closure(array<int, string>): string}>  $rules
     */
    public function __construct(
        private readonly array $rules,
    ) {}

    /**
     * @param  list<string>  $queryParameterWords
     * @param  list<string>  $extraPatterns
     */
    public static function fromRules(bool $secrets, bool $personalData, array $queryParameterWords, array $extraPatterns): self
    {
        $rules = [];

        if ($secrets) {
            $rules = self::secretRules($queryParameterWords);
        }

        if ($personalData) {
            foreach (self::PERSONAL_DATA_PATTERNS as $pattern) {
                $rules[] = [$pattern, Scrubber::MASK];
            }
        }

        foreach ($extraPatterns as $pattern) {
            if (self::isValidPattern($pattern)) {
                $rules[] = [$pattern, Scrubber::MASK];
            }
        }

        return new self($rules);
    }

    public static function isValidPattern(string $pattern): bool
    {
        return @preg_match($pattern, '') !== false;
    }

    /**
     * Redacts a string, or every string key and value of an array of any depth.
     */
    public function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$this->uniqueKey(is_string($key) ? $this->redactString($key) : $key, $redacted)] = $this->redact($item);
        }

        return $redacted;
    }

    public function redactString(string $value): string
    {
        foreach ($this->rules as [$pattern, $replacement]) {
            $value = is_string($replacement)
                ? preg_replace($pattern, $replacement, $value)
                : preg_replace_callback($pattern, $replacement, $value);

            if (! is_string($value)) {
                return Scrubber::MASK;
            }
        }

        return $value;
    }

    /**
     * @param  list<string>  $queryParameterWords
     * @return list<array{string, string|Closure(array<int, string>): string}>
     */
    private static function secretRules(array $queryParameterWords): array
    {
        $words = array_values(array_unique(array_filter(
            array_map(strtolower(...), $queryParameterWords),
            fn (string $word): bool => $word !== '',
        )));

        return [
            [self::CREDENTIAL_PATTERN, fn (array $match): string => self::looksLikeCredential($match[3]) ? sprintf('%s%s%s', $match[1], $match[2], Scrubber::MASK) : $match[0]],
            [self::URL_PASSWORD_PATTERN, sprintf('$1%s@', Scrubber::MASK)],
            [self::BASE64_KEY_PATTERN, sprintf('base64:%s', Scrubber::MASK)],
            [self::QUERY_PARAMETER_PATTERN, fn (array $match): string => self::maskQueryParameter($match[1], $match[2], $words)],
            [self::JWT_PATTERN, Scrubber::MASK],
            [self::OPAQUE_PATTERN, fn (array $match): string => self::isOpaque($match[0]) ? Scrubber::MASK : $match[0]],
        ];
    }

    /**
     * A credential has something a word does not: a digit, a symbol or a lower case letter followed by a capital.
     */
    private static function looksLikeCredential(string $value): bool
    {
        return preg_match('/[0-9._~+\/=-]|[a-z][A-Z]/', $value) === 1;
    }

    /**
     * @param  list<string>  $words
     */
    private static function maskQueryParameter(string $name, string $value, array $words): string
    {
        $secret = rtrim($value, '.!?:');
        $trailing = substr($value, strlen($secret));

        if ($secret === '' || ! self::isSecretName(rtrim($name, '="\''), $words)) {
            return sprintf('%s%s', $name, $value);
        }

        return sprintf('%s%s%s', $name, Scrubber::MASK, $trailing);
    }

    /**
     * A name is secret when one of its parts is a word, or ends with a word of five or more letters.
     *
     * @param  list<string>  $words
     */
    private static function isSecretName(string $name, array $words): bool
    {
        $parts = preg_split('/[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($parts as $part) {
            $part = strtolower($part);

            foreach ($words as $word) {
                if ($part === $word || (strlen($word) >= 5 && str_ends_with($part, $word))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A run is opaque when one of its parts looks random: it has a digit, and it switches between letters
     * and digits at least four times, or it has 16 or more characters and changes between lower case,
     * capitals and digits at every other character. A UUID is never opaque.
     */
    private static function isOpaque(string $run): bool
    {
        if (preg_match(self::UUID_PATTERN, $run) === 1) {
            return false;
        }

        foreach (preg_split('/[_-]+/', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/\d/', $part) === 1 && self::looksRandom($part)) {
                return true;
            }
        }

        return false;
    }

    private static function looksRandom(string $part): bool
    {
        $classes = array_map(fn (string $character): string => match (true) {
            ctype_digit($character) => 'digit',
            ctype_upper($character) => 'upper',
            default => 'lower',
        }, str_split($part));

        $digitSwitches = 0;
        $changes = 0;

        for ($index = 1, $length = count($classes); $index < $length; $index++) {
            if ($classes[$index] === $classes[$index - 1]) {
                continue;
            }

            $changes++;

            if ($classes[$index] === 'digit' || $classes[$index - 1] === 'digit') {
                $digitSwitches++;
            }
        }

        return $digitSwitches >= 4 || (count($classes) >= 16 && $changes * 2 >= count($classes));
    }

    /**
     * @param  array<array-key, mixed>  $redacted
     */
    private function uniqueKey(int|string $key, array $redacted): int|string
    {
        if (! array_key_exists($key, $redacted)) {
            return $key;
        }

        $suffix = 2;

        while (array_key_exists(sprintf('%s_%d', $key, $suffix), $redacted)) {
            $suffix++;
        }

        return sprintf('%s_%d', $key, $suffix);
    }
}
