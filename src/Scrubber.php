<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

class Scrubber
{
    public const string MASK = '[scrubbed]';

    /**
     * @param  list<string>  $words
     */
    public function __construct(
        private readonly array $words,
    ) {}

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function scrub(array $values): array
    {
        $scrubbed = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $scrubbed[$key] = self::MASK;

                continue;
            }

            $scrubbed[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $scrubbed;
    }

    public function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->words as $word) {
            if ($word !== '' && str_contains($key, strtolower($word))) {
                return true;
            }
        }

        return false;
    }
}
