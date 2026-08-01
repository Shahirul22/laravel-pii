<?php

namespace Shahirul22\LaravelPiiSanitizer\Exceptions;

final class UnsafeColumnException extends \RuntimeException
{
    /**
     * @param  class-string  $modelClass
     */
    public static function outboundForeignKey(string $modelClass, string $column, string $sanitizerClass, string $table, string $referencedTable): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} is a foreign key on table \"{$table}\" (references \"{$referencedTable}\") and cannot be sanitized. Remove it from {$sanitizerClass}::fields() — v1 sanitizes flat columns only."
        );
    }

    /**
     * @param  class-string  $modelClass
     */
    public static function inboundReference(string $modelClass, string $column, string $sanitizerClass, string $table, string $referencingTable): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} is referenced by a foreign key on table \"{$referencingTable}\" and cannot be sanitized. Remove it from {$sanitizerClass}::fields() — v1 sanitizes flat columns only."
        );
    }

    /**
     * @param  class-string  $modelClass
     */
    public static function unknownColumn(string $modelClass, string $column, string $sanitizerClass, string $table): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} does not exist on table \"{$table}\". Check the column name in {$sanitizerClass}::fields()."
        );
    }
}
