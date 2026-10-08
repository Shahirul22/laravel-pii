<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

use Random\Randomizer;
use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

/**
 * One of the 16 Malaysian states and federal territories, picked with a single
 * getInt(0, 15). The input value is ignored.
 *
 * A keyed state on a non-unique column is a pure namespace: many inputs map to
 * the same state, by design.
 */
final class State extends RandomizedValue
{
    public const STATES = [
        'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis',
        'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu', 'W.P. Kuala Lumpur',
        'W.P. Labuan', 'W.P. Putrajaya',
    ];

    public function generate(mixed $value, Randomizer $random): string
    {
        return self::STATES[$random->getInt(0, count(self::STATES) - 1)];
    }

    public function signature(): string
    {
        return 'state';
    }
}
