<?php

namespace Shahirul22\LaravelPiiSanitizer\Contracts;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable, invokable replacement-value generator.
 *
 * Implement this to encapsulate value-generation logic that is reused across
 * multiple fields or models (R3.3), then reference the implementing class by
 * class-string in a Sanitizer's fields() map.
 */
interface ValueGenerator
{
    public function __invoke(mixed $value, Generator $faker, Model $row): mixed;
}
