<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;

class TimestampPolicy
{
    /**
     * The set of columns that are never written by a sanitize run unless
     * explicitly declared in a Sanitizer's fields().
     *
     * @return list<string>
     */
    public function protectedColumns(Model $model): array
    {
        $configured = array_filter(
            config('pii.protected_columns', []),
            static fn (mixed $column): bool => is_string($column) && $column !== ''
        );

        $columns = [
            ...$configured,
            $model->getCreatedAtColumn(),
            $model->getUpdatedAtColumn(),
        ];

        if (method_exists($model, 'getDeletedAtColumn')) {
            $columns[] = $model->getDeletedAtColumn();
        }

        $columns = array_values(array_filter($columns, static fn (mixed $column): bool => is_string($column) && $column !== ''));

        return array_values(array_unique($columns));
    }

    /**
     * Whether the engine must disable Eloquent's automatic timestamp
     * maintenance for the write. True whenever the model maintains
     * automatic timestamps ($model->usesTimestamps()), regardless of
     * whether a timestamp column is declared in the sanitizer's fields() —
     * this both prevents an undeclared updated_at from being silently
     * bumped and, when a timestamp column IS explicitly declared, prevents
     * Eloquent from overwriting the sanitizer's own generated value.
     */
    public function mustSuppressAutomaticTimestamps(Sanitizer $sanitizer, Model $model): bool
    {
        return $model->usesTimestamps();
    }
}
