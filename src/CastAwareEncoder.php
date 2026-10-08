<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsupportedCastException;

/**
 * Encodes a sanitize-generated logical value through a model's own
 * cast/mutator pipeline on a throwaway, never-persisted probe instance, so a
 * cast-bearing column (e.g. `encrypted`) is written exactly as Eloquent
 * itself would store it — see docs/design/engine-hardening/spec §R5.
 */
final class CastAwareEncoder
{
    /**
     * The cast-bearing subset of $columns, in input order.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public function classify(Model $model, array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column): bool => $model->hasCast($column)
                || $model->hasSetMutator($column)
                || $model->hasAttributeSetMutator($column)
        ));
    }

    /**
     * Runs $value through $row's own cast/mutator pipeline for $column,
     * without persisting anything or mutating $row, and returns the exact
     * value Eloquent would have stored.
     *
     * @throws UnsupportedCastException
     */
    public function encode(Model $row, string $column, mixed $value): mixed
    {
        $original = $row->getAttributes();

        try {
            $probe = $row->newInstance([], true);
            $probe->setRawAttributes($original, true);
            $probe->setAttribute($column, $value);
            $stored = $probe->getAttributes();
        } catch (\Throwable $e) {
            throw UnsupportedCastException::encodeFailed($row::class, $column, $row->getTable(), $e::class);
        }

        $touched = [];

        foreach (array_unique([...array_keys($original), ...array_keys($stored)]) as $key) {
            if ($key === $column) {
                continue;
            }

            if (! array_key_exists($key, $original) || ! array_key_exists($key, $stored) || $original[$key] !== $stored[$key]) {
                $touched[] = (string) $key;
            }
        }

        if ($touched !== []) {
            throw UnsupportedCastException::multiColumn($row::class, $column, $row->getTable(), $touched);
        }

        if (! array_key_exists($column, $stored)) {
            throw UnsupportedCastException::encodeFailed($row::class, $column, $row->getTable(), 'no stored value produced');
        }

        return $stored[$column];
    }
}
