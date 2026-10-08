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
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} is a foreign key on table \"{$table}\" (references \"{$referencedTable}\") and cannot be sanitized. Remove it from {$sanitizerClass}::fields() — v1 sanitizes flat columns only. To sanitize it consistently with the columns that hold the same value, declare it in mirrors() with a Keyed definition."
        );
    }

    /**
     * @param  class-string  $modelClass
     */
    public static function inboundReference(string $modelClass, string $column, string $sanitizerClass, string $table, string $referencingTable): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} is referenced by a foreign key on table \"{$referencingTable}\" and cannot be sanitized. Remove it from {$sanitizerClass}::fields() — v1 sanitizes flat columns only. To sanitize it consistently with the columns that hold the same value, declare it in mirrors() with a Keyed definition."
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

    /**
     * @param  class-string  $modelClass
     */
    public static function primaryKey(string $modelClass, string $column, string $sanitizerClass, string $table): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} is the primary key of table \"{$table}\" and cannot be sanitized — chunkById() pages and orders by it. Remove it from {$sanitizerClass}::fields()."
        );
    }

    /**
     * @param  class-string  $modelClass
     * @param  array{child_table: string, child_column: string, parent_table: string, parent_column: string}  $edge
     */
    public static function undeclaredReference(string $modelClass, string $column, string $sanitizerClass, string $table, array $edge): self
    {
        $childTable = $edge['child_table'];
        $childColumn = $edge['child_column'];
        $parentTable = $edge['parent_table'];
        $parentColumn = $edge['parent_column'];

        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} is opted in through {$sanitizerClass}::mirrors(), but the foreign key {$childTable}.{$childColumn} -> {$parentTable}.{$parentColumn} links it to a column that is not declared as a mirror. Declare that column as a mirror and sanitize it with the same Keyed namespace, or remove the opt-in. The package never adds a mirror on its own."
        );
    }

    public static function foreignKeySuspensionUnavailable(string $driver, string $reason): self
    {
        return new self(
            "[laravel-pii-sanitizer] Opted-in referenced columns need foreign-key enforcement suspended for this run, which is not possible here: {$reason}."
        );
    }
}
