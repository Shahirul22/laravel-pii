<?php

namespace Shahirul22\LaravelPiiSanitizer\Exceptions;

final class UniquenessExhaustedException extends \RuntimeException
{
    /**
     * @param  class-string  $modelClass
     * @param  list<string>  $columns
     */
    public static function forConstraint(string $modelClass, array $columns, string $sanitizerClass, string $table, int $attempts): self
    {
        $columnList = implode(', ', array_map(
            static fn (string $column): string => "\${$column}",
            $columns
        ));

        $target = count($columns) === 1
            ? "{$modelClass}::\${$columns[0]}"
            : "{$modelClass}::[{$columnList}]";

        return new self(
            "[laravel-pii-sanitizer] Could not generate a unique value for {$target} on table \"{$table}\" after {$attempts} attempts. The value-definition in {$sanitizerClass}::fields() produces too small a value space for this column — widen it (e.g. \$faker->unique()->safeEmail(), or append the row's primary key)."
        );
    }

    public static function keyedProbesExhausted(string $namespace, int $probes): self
    {
        return new self(
            "[laravel-pii-sanitizer] Keyed namespace \"{$namespace}\" could not find a unique replacement after {$probes} probes. The shape's value space is too small for the unique column(s) it is bound to — widen it (a longer pattern or a larger shape)."
        );
    }

    /**
     * @param  class-string  $modelClass
     * @param  list<string>  $columns
     */
    public static function keyedConflict(string $modelClass, array $columns, string $sanitizerClass, string $table): self
    {
        $columnList = implode(', ', array_map(
            static fn (string $column): string => "\${$column}",
            $columns
        ));

        $target = count($columns) === 1
            ? "{$modelClass}::\${$columns[0]}"
            : "{$modelClass}::[{$columnList}]";

        return new self(
            "[laravel-pii-sanitizer] Keyed values for {$target} on table \"{$table}\" collide on a unique constraint with no non-keyed column left to regenerate. Every Keyed column in {$sanitizerClass}::fields() must be registered by the sanitize run before rows are generated."
        );
    }
}
