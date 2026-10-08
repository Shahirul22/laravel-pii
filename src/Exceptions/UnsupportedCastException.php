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

    public static function undecodableValue(string $modelClass, string $column, string $table, string $cast, string $reason): self
    {
        return new self(
            "[laravel-pii-sanitizer] Cannot sanitize {$modelClass}::\${$column} on table \"{$table}\": its stored value could not be read through the cast \"{$cast}\" ({$reason}), and the column's value definition reads the current value. For an encrypted cast this usually means the data was encrypted with another APP_KEY, as after importing a production dump. Use a static value or a Faker formatter name for this column, which never read the current value, or set APP_KEY to the key the data was encrypted with."
        );
    }
}
