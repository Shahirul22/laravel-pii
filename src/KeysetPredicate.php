<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The key-set page filter `identity > last_seen` used by
 * SanitizationRunner::chunkByKeyset(). The identity is unique, NOT NULL and
 * ordered ascending on every column (PagingKeyResolver), so the row-value
 * comparison `(a, b) > (?, ?)` and the expanded chain
 * `(a > ?) OR (a = ? AND b > ?)` select exactly the same rows. They differ
 * only in how the database plans them:
 *
 * - PostgreSQL applies the OR chain as a Filter over an index scan from the
 *   start of the table, so page k re-reads every earlier page and a full
 *   walk is quadratic; the row-value form is an Index Cond. Row values are
 *   used there.
 * - SQLite 3.15 and later turns both forms into an index range. Row values
 *   are used there.
 * - MySQL 8.4 is the other way round: the OR chain is an index range scan
 *   and the row-value form a full index scan plus a filter. MySQL and
 *   MariaDB keep the OR chain, and so does any other driver.
 *
 * Internal to the package.
 */
final class KeysetPredicate
{
    public static function supportsRowValues(string $driver, string $serverVersion): bool
    {
        return match ($driver) {
            'pgsql' => true,
            'sqlite' => version_compare($serverVersion, '3.15.0', '>='),
            default => false,
        };
    }

    /**
     * Adds the `identity > last_seen` filter to one page query. A
     * single-column identity always gets the plain `(a > ?)` comparison.
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $lastSeen
     */
    public static function apply(Builder $query, array $columns, array $lastSeen, bool $rowValues): void
    {
        if ($rowValues && count($columns) > 1) {
            $grammar = $query->getQuery()->getGrammar();
            $bindings = [];

            foreach ($columns as $column) {
                $bindings[] = $lastSeen[$column];
            }

            $query->whereRaw(sprintf(
                '(%s) > (%s)',
                implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns)),
                implode(', ', array_fill(0, count($columns), '?'))
            ), $bindings);

            return;
        }

        $query->where(function (Builder $outer) use ($columns, $lastSeen): void {
            foreach ($columns as $i => $column) {
                $outer->orWhere(function (Builder $branch) use ($columns, $lastSeen, $i, $column): void {
                    for ($j = 0; $j < $i; $j++) {
                        $branch->where($columns[$j], '=', $lastSeen[$columns[$j]]);
                    }

                    $branch->where($column, '>', $lastSeen[$column]);
                });
            }
        });
    }
}
