<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

use Faker\Provider\Base;
use Shahirul22\LaravelPiiSanitizer\Values\Malaysia;
use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

/**
 * Faker shorthand for the Malaysia generators: 'malaysiaNric', 'malaysiaSst',
 * 'malaysiaState' and 'malaysiaBankAccount' as value-definition strings.
 *
 * The 'malaysia' prefix avoids collisions with Faker's own formatter names.
 * Randomness comes from the same container Faker (so a seeded $faker->seed(n)
 * stays reproducible) via the random-mode bridge. A bare Factory::create()
 * Faker does not carry this provider; the shorthand is a container feature.
 */
final class MalaysiaProvider extends Base
{
    public function malaysiaNric(bool $hyphen = false, ?string $gender = null): string
    {
        return Malaysia::nric($hyphen, $gender)->generate(null, RandomizedValue::randomizer($this->generator));
    }

    public function malaysiaSst(): string
    {
        return Malaysia::sst()->generate(null, RandomizedValue::randomizer($this->generator));
    }

    public function malaysiaState(): string
    {
        return Malaysia::state()->generate(null, RandomizedValue::randomizer($this->generator));
    }

    public function malaysiaBankAccount(?string $bank = null): string
    {
        return Malaysia::bankAccount($bank)->generate(null, RandomizedValue::randomizer($this->generator));
    }
}
