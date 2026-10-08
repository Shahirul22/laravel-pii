<?php

namespace Shahirul22\LaravelPiiSanitizer\Exceptions;

use Shahirul22\LaravelPiiSanitizer\DistributionSampler;

final class InvalidCategoricalColumnException extends \RuntimeException
{
    /**
     * @param  class-string  $modelClass
     */
    public static function notDeclared(string $modelClass, string $column, string $sanitizerClass): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} is listed in {$sanitizerClass}::categorical() but not in fields(). Add it to fields() — categorical() only changes how a declared column's value is generated."
        );
    }

    /**
     * @param  class-string  $modelClass
     */
    public static function uniqueConstrained(string $modelClass, string $column, string $sanitizerClass, string $table): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} carries a unique constraint on table \"{$table}\" and cannot preserve a value distribution. Remove it from {$sanitizerClass}::categorical()."
        );
    }

    /**
     * @param  class-string  $modelClass
     */
    public static function tooManyCategories(string $modelClass, string $column, string $table, int $distinct): self
    {
        $limit = DistributionSampler::MAX_CATEGORIES;

        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} has {$distinct} distinct values on table \"{$table}\" and is not categorical (limit {$limit}). Remove it from categorical()."
        );
    }

    /**
     * @param  class-string  $modelClass
     */
    public static function keyed(string $modelClass, string $column, string $sanitizerClass): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} uses a Keyed value-definition and is listed in {$sanitizerClass}::categorical(). A categorical column is sampled from its distribution, which would override the keyed value — remove it from categorical()."
        );
    }

    /**
     * @param  class-string  $modelClass
     */
    public static function jsonPaths(string $modelClass, string $column, string $sanitizerClass): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} uses a Json::paths() definition and is listed in {$sanitizerClass}::categorical(). Sampling would replace the whole document with another row's value, so remove it from categorical()."
        );
    }
}
