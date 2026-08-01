<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;

/**
 * Resolves a field's value-definition into a concrete replacement value.
 *
 * A value-definition is one of four types, dispatched by PHP type/shape in
 * this order:
 *  1. Closure — invoked directly with (value, faker, row).
 *  2. Invokable class-string implementing ValueGenerator — resolved via the
 *     container and invoked with (value, faker, row). Checked before the
 *     Faker-shorthand branch so a ValueGenerator class name is never
 *     mistaken for a Faker method name.
 *  3. Faker method-name shorthand string — invoked on the given Faker
 *     instance.
 *  4. Anything else — returned unchanged as a static value (the fallback).
 */
class ValueDefinitionResolver
{
    public function resolve(mixed $definition, mixed $currentValue, Generator $faker, Model $row): mixed
    {
        if ($definition instanceof \Closure) {
            return $definition($currentValue, $faker, $row);
        }

        if (is_string($definition) && class_exists($definition) && is_subclass_of($definition, ValueGenerator::class)) {
            $generator = app($definition);

            assert($generator instanceof ValueGenerator);

            return $generator($currentValue, $faker, $row);
        }

        if (is_string($definition) && $this->isFakerFormatter($definition, $faker)) {
            return $faker->{$definition}();
        }

        return $definition;
    }

    /**
     * Determine whether $name is a resolvable Faker formatter/method name.
     *
     * Faker\Generator dispatches shorthand names (e.g. 'email', 'safeEmail')
     * to its registered providers via __call()/getFormatter(), so they are
     * not native methods on the Generator itself — method_exists() would
     * always return false. getFormatter() is Faker's own public mechanism
     * for resolving a format name to a callable, throwing when unknown.
     */
    private function isFakerFormatter(string $name, Generator $faker): bool
    {
        try {
            $faker->getFormatter($name);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
