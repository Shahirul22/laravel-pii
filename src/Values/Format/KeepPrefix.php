<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Format;

use Random\Randomizer;

/**
 * Keeps the first N code points and replaces the rest by class. A value no
 * longer than N is replaced entirely; the original is never returned intact.
 */
final class KeepPrefix extends FormatPreservingValue
{
    /**
     * @throws \InvalidArgumentException when $count is less than 1
     */
    public function __construct(private readonly int $count)
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('[laravel-pii-sanitizer] keepPrefix needs a count of at least 1.');
        }
    }

    protected function transform(array $characters, Randomizer $random): string
    {
        if (count($characters) <= $this->count) {
            return self::replace($characters, $random);
        }

        return implode('', array_slice($characters, 0, $this->count))
            .self::replace(array_slice($characters, $this->count), $random);
    }

    public function signature(): string
    {
        return 'keepPrefix:'.$this->count;
    }
}
