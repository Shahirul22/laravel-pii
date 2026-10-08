<?php

namespace Shahirul22\LaravelPiiSanitizer\Values;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Shahirul22\LaravelPiiSanitizer\Contracts\RandomizedGenerator;
use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;

/**
 * Base class for value shapes that are usable both bare and wrapped in Keyed.
 *
 * __invoke() is the random-mode bridge: it builds a Randomizer from the
 * Faker stream so a seeded $faker->seed(n) in tests still makes a bare
 * helper reproducible. A bare instance is therefore a random-mode
 * value-definition and goes through v1's retry loop on unique columns;
 * wrapped in Keyed it is deterministic (the Keyed path draws from an
 * HMAC-seeded Randomizer instead and never touches Faker).
 *
 * randomizer() is that bridge as a static method, shared with
 * Malaysia\MalaysiaProvider so it exists in exactly one place.
 *
 * fields() is evaluated per row, so subclass constructors must stay cheap
 * and free of side effects.
 *
 * See docs/design/value-generation-primitives/spec, "The value-definition seam".
 */
abstract class RandomizedValue implements RandomizedGenerator, ValueGenerator
{
    public function __invoke(mixed $value, Generator $faker, Model $row): mixed
    {
        if ($value === null) {
            return null;
        }

        return $this->generate($value, self::randomizer($faker));
    }

    /**
     * The random-mode bridge: one Faker draw seeds an Mt19937 Randomizer, so a
     * seeded $faker->seed(n) makes every bare shape reproducible. Byte-exact; see
     * docs/design/value-generation-primitives/spec, "The value-definition seam".
     */
    public static function randomizer(Generator $faker): Randomizer
    {
        return new Randomizer(new Mt19937($faker->numberBetween(0, 2147483647)));
    }
}
