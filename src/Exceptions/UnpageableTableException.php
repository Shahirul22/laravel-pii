<?php

namespace Shahirul22\LaravelPiiSanitizer\Exceptions;

final class UnpageableTableException extends \RuntimeException
{
    public static function noStableIdentity(string $modelClass, string $table, string $sanitizerClass): self
    {
        return new self(
            "[laravel-pii-sanitizer] Cannot page {$table} ({$modelClass}, sanitized by {$sanitizerClass}): no primary key, no NOT NULL unique index disjoint from fields(), and no column set proven unique at boot. Declare {$sanitizerClass}::pagingKey() with NOT NULL columns that uniquely identify each row and are not in fields()."
        );
    }

    public static function optedInPrimaryKey(string $modelClass, string $table, string $sanitizerClass, string $column): self
    {
        return new self(
            "[laravel-pii-sanitizer] Cannot page {$table} ({$modelClass}, sanitized by {$sanitizerClass}): its primary-key column \${$column} is opted in through mirrors() and will be rewritten, and no other NOT NULL unique column set disjoint from fields() was found. Declare {$sanitizerClass}::pagingKey(), or remove the opt-in."
        );
    }
}
