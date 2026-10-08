<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Format;

use Random\Randomizer;

/**
 * Replaces every letter and digit, keeping the length (in code points) and
 * every separator. With a one-character mask each replaced character becomes
 * that mask instead of a random one.
 *
 * A masked helper is deterministic, so on a unique column it exhausts v1's
 * 100-attempt retry loop (and, wrapped in Keyed on a unique-bound namespace,
 * the keyed probes). Use the unmasked form, or Keyed around it, on unique
 * columns.
 */
final class KeepLength extends FormatPreservingValue
{
    /**
     * @throws \InvalidArgumentException when the mask is not exactly one UTF-8 code point
     */
    public function __construct(private readonly ?string $mask = null)
    {
        if ($mask !== null && (! mb_check_encoding($mask, 'UTF-8') || mb_strlen($mask, 'UTF-8') !== 1)) {
            throw new \InvalidArgumentException('[laravel-pii-sanitizer] A keepLength mask must be exactly one UTF-8 code point.');
        }
    }

    protected function transform(array $characters, Randomizer $random): string
    {
        if ($this->mask === null) {
            return self::replace($characters, $random);
        }

        $output = '';

        foreach ($characters as $character) {
            $output .= self::isReplaceable($character) ? $this->mask : $character;
        }

        return $output;
    }

    public function signature(): string
    {
        return $this->mask === null ? 'keepLength' : 'keepLength:'.$this->mask;
    }
}
