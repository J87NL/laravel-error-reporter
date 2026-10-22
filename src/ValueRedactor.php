<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

class ValueRedactor
{
    private const array CREDENTIAL_PATTERNS = [
        '/\b(Bearer|Basic)\s+[^\s"\',;]+/i' => '$1 '.Scrubber::MASK,
    ];

    private const array TOKEN_PATTERNS = [
        '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/' => Scrubber::MASK,
        '/(?<![A-Za-z0-9_-])(?=[A-Za-z_-]*\d)[A-Za-z0-9_-]{32,}(?![A-Za-z0-9_-])/' => Scrubber::MASK,
    ];

    private const array PERSONAL_DATA_PATTERNS = [
        '/[^\s<>"\'(),;:\[\]]+@[^\s<>"\'(),;:\[\]]+\.[a-z]{2,}/i' => Scrubber::MASK,
        '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]){10,30}\b/' => Scrubber::MASK,
        '/(?<![\w+])(?:\+31|0031|0)[\s-]?\d(?:[\s-]?\d){8}(?!\d)/' => Scrubber::MASK,
    ];

    /**
     * @param  array<string, string>  $patterns
     */
    public function __construct(
        private readonly array $patterns,
    ) {}

    /**
     * @param  list<string>  $queryParameterWords
     * @param  list<string>  $extraPatterns
     */
    public static function fromRules(bool $secrets, bool $personalData, array $queryParameterWords, array $extraPatterns): self
    {
        $patterns = [];

        if ($secrets) {
            $patterns = [
                ...self::CREDENTIAL_PATTERNS,
                ...self::queryParameterPattern($queryParameterWords),
                ...self::TOKEN_PATTERNS,
            ];
        }

        if ($personalData) {
            $patterns = [
                ...$patterns,
                ...self::PERSONAL_DATA_PATTERNS,
            ];
        }

        foreach ($extraPatterns as $pattern) {
            if (@preg_match($pattern, '') !== false) {
                $patterns[$pattern] = Scrubber::MASK;
            }
        }

        return new self($patterns);
    }

    public function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->redact($item), $value);
        }

        return $value;
    }

    public function redactString(string $value): string
    {
        if ($this->patterns === []) {
            return $value;
        }

        return preg_replace(array_keys($this->patterns), array_values($this->patterns), $value) ?? Scrubber::MASK;
    }

    /**
     * @param  list<string>  $words
     * @return array<string, string>
     */
    private static function queryParameterPattern(array $words): array
    {
        $words = array_values(array_unique(array_filter($words, fn (string $word): bool => $word !== '')));

        if ($words === []) {
            return [];
        }

        $alternatives = implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), $words));

        return [
            sprintf('/([?&][^=&\s]*(?:%s)[^=&\s]*=)[^&#\s"\']+/i', $alternatives) => sprintf('$1%s', Scrubber::MASK),
        ];
    }
}
