<?php

namespace Shahirul22\LaravelPiiSanitizer\Exceptions;

final class UnsupportedCastException extends \RuntimeException
{
    /**
     * @param  list<string>  $touched
     */
    public static function multiColumn(string $modelClass, string $column, string $table, array $touched): self
    {
        return new self(
            "[laravel-pii-sanitizer] Cannot sanitize {$modelClass}::\${$column} on table \"{$table}\": its cast/mutator also writes to other attributes (".implode(', ', $touched).'), which were not declared for sanitization. Remove $'."{$column} from the sanitizer's fields() or replace the multi-attribute cast."
        );
    }

    public static function encodeFailed(string $modelClass, string $column, string $table, string $reason): self
    {
        return new self(
            "[laravel-pii-sanitizer] Cannot sanitize {$modelClass}::\${$column} on table \"{$table}\": the model's cast/mutator failed to encode the replacement value ({$reason}). Check the cast's requirements (e.g. APP_KEY for encrypted casts) or remove the column from fields()."
        );
    }
}
