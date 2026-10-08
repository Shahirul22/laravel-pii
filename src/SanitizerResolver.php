<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\DataQualityGuardContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\SchemaGuardContract;
use Shahirul22\LaravelPiiSanitizer\Exceptions\ConstraintViolationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;

class SanitizerResolver implements SanitizerResolverContract
{
    public function __construct(
        private readonly SchemaGuardContract $guard,
        private readonly DataQualityGuardContract $qualityGuard,
    ) {}

    /**
     * Resolve the sanitizer for a given model (instance or class-string).
     *
     * Resolution order:
     *  1. An explicit `config('pii.sanitizers')` mapping entry (always wins).
     *  2. The conventional `App\Sanitizers\{Model}Sanitizer` class, if it exists.
     *  3. Null, when neither resolves — callers treat this as "skip".
     *
     * @throws UnsafeColumnException
     * @throws InvalidCategoricalColumnException
     * @throws ConstraintViolationException
     *
     * Rejection (an unsafe FK/FK-referenced column declared in the resolved
     * sanitizer's fields(), an invalid categorical() declaration, or a
     * declaration-determinable column-constraint violation per §R6.3)
     * happens here, at resolution time — before any row is read or
     * written.
     */
    public function resolve(Model|string $model): ?Sanitizer
    {
        $class = is_object($model) ? $model::class : $model;

        $overrides = config('pii.sanitizers', []);

        if (is_array($overrides) && array_key_exists($class, $overrides)) {
            $sanitizerClass = $overrides[$class];

            if (is_string($sanitizerClass) && $sanitizerClass !== '') {
                return $this->make($sanitizerClass, $model);
            }
        }

        $conventional = 'App\\Sanitizers\\'.class_basename($class).'Sanitizer';

        if (class_exists($conventional)) {
            return $this->make($conventional, $model);
        }

        return null;
    }

    /**
     * Config-only lookup against `pii.tables` -- there is no convention
     * lookup for a bare table name (a table has no class name to
     * convention-match against). See
     * docs/design/engine-hardening/spec §R7 Model-less table target.
     *
     * @throws UnsafeColumnException
     */
    public function resolveTable(string $table): ?Sanitizer
    {
        $map = config('pii.tables', []);

        if (is_array($map) && array_key_exists($table, $map) && is_string($map[$table]) && $map[$table] !== '') {
            return $this->make($map[$table], TableRow::forTable($table));
        }

        return null;
    }

    /**
     * @param  class-string  $sanitizerClass
     */
    private function make(string $sanitizerClass, Model|string $model): Sanitizer
    {
        // A missing class would otherwise surface as the container's raw
        // BindingResolutionException.
        if (! class_exists($sanitizerClass) && ! app()->bound($sanitizerClass)) {
            throw InvalidConfigurationException::invalidSanitizerClass($sanitizerClass);
        }

        $instance = app($sanitizerClass);

        if (! $instance instanceof Sanitizer) {
            throw InvalidConfigurationException::invalidSanitizerClass($sanitizerClass);
        }

        $this->guard->assertSafe($instance, $model);
        $this->qualityGuard->assertValid($instance, $model);

        return $instance;
    }
}
