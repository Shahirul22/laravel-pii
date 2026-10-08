<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Format;

use Random\Randomizer;

/**
 * Keeps the last N code points and replaces the rest by class. A value no
 * longer than N is replaced entirely; the original is never returned intact.
 */
final class KeepLast extends FormatPreservingValue
{
    /**
     * @throws \InvalidArgumentException when $count is less than 1
     */
    public function __construct(private readonly int $count)
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('[laravel-pii-sanitizer] keepLast needs a count of at least 1.');
        }
    }

    protected function transform(array $characters, Randomizer $random): string
    {
        if (count($characters) <= $this->count) {
            return self::replace($characters, $random);
        }

        return self::replace(array_slice($characters, 0, -$this->count), $random)
            .implode('', array_slice($characters, -$this->count));
    }

    public function signature(): string
    {
        return 'keepLast:'.$this->count;
    }
}
