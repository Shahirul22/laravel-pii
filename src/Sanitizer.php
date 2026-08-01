<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;

abstract class Sanitizer
{
    /**
     * Map of column name => value-definition.
     *
     * A value-definition is one of:
     *  - a static value (any scalar, array, enum, null)
     *  - a Closure: fn(mixed $value, \Faker\Generator $faker, Model $row): mixed
     *  - a class-string implementing ValueGenerator
     *  - a Faker method-name string (e.g. 'email', 'name')
     *
     * @return array<string, mixed>
     */
    abstract public function fields(): array;

    /**
     * Columns whose replacement values must preserve the column's original
     * value distribution (R4.2). Each entry MUST also appear in fields().
     *
     * @return list<string>
     */
    public function categorical(): array
    {
        return [];
    }

    /**
     * Resolve the sanitizer for a given model (instance or class-string),
     * or null when the model has no sanitizer.
     */
    public static function for(Model|string $model): ?Sanitizer
    {
        return app(SanitizerResolverContract::class)->resolve($model);
    }
}
