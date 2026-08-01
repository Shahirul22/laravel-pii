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
}
