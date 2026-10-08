<?php

namespace Shahirul22\LaravelPiiSanitizer\Exceptions;

final class ConstraintViolationException extends \RuntimeException
{
    public static function notNullable(string $modelClass, string $column, string $table): self
    {
        return new self(
            "[laravel-pii-sanitizer] Cannot sanitize {$modelClass}::\${$column} on table \"{$table}\": the replacement value is null but the column is NOT NULL (nullability constraint). Return a non-null value from its definition in fields()."
        );
    }

    public static function tooLong(string $modelClass, string $column, string $table, int $maxLength, int $actualLength): self
    {
        return new self(
            "[laravel-pii-sanitizer] Cannot sanitize {$modelClass}::\${$column} on table \"{$table}\": the replacement value is {$actualLength} characters but the column allows at most {$maxLength} (length constraint; for a cast-bearing column such as encrypted this is the stored, encoded length). Shorten the value its definition in fields() produces."
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function notInAllowedSet(string $modelClass, string $column, string $table, array $allowed): self
    {
        return new self(
            "[laravel-pii-sanitizer] Cannot sanitize {$modelClass}::\${$column} on table \"{$table}\": the replacement value is not in the column's allowed set (enum/set constraint: ".implode(', ', $allowed).'). Return one of the allowed values from its definition in fields().'
        );
    }

    public static function typeMismatch(string $modelClass, string $column, string $table, string $expectedFamily): self
    {
        return new self(
            "[laravel-pii-sanitizer] Cannot sanitize {$modelClass}::\${$column} on table \"{$table}\": the replacement value does not match the column's {$expectedFamily} type (type constraint). Return a value of that type from its definition in fields()."
        );
    }
}
