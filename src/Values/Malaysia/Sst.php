<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

use Random\Randomizer;
use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

/**
 * A Malaysian SST registration number in the shape ^[A-Z]\d{2}-\d{4}-\d{8}$,
 * for example W10-1808-32000064. The input value is ignored.
 *
 * The prefix is W10 or B16, the middle group is a valid YYMM drawn from the
 * 100-value space 1809..2612 (year-18 months restricted to 09-12), and the last
 * group is eight random digits.
 *
 * Draw order is part of the keyed derivation contract and must not change:
 * (1) prefix index, (2) YYMM index, (3) final group.
 *
 * Assumption taken from publicly cited registration numbers, not verified
 * against an RMCD/MySST source (design spec Open items).
 */
final class Sst extends RandomizedValue
{
    public const PREFIXES = ['W10', 'B16'];

    public function generate(mixed $value, Randomizer $random): string
    {
        $prefix = self::PREFIXES[$random->getInt(0, 1)];
        $index = $random->getInt(0, 99);
        [$yy, $mm] = $index < 4
            ? [18, 9 + $index]
            : [19 + intdiv($index - 4, 12), ($index - 4) % 12 + 1];

        return sprintf('%s-%02d%02d-%08d', $prefix, $yy, $mm, $random->getInt(0, 99999999));
    }

    public function signature(): string
    {
        return 'sst';
    }
}
