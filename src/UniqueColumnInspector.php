<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\DatabaseManager;

/**
 * Introspects unique (and primary-key) constraints on a table, so
 * uniqueness-preserving generation can be driven by schema fact rather
 * than author opt-in (R4.1).
 */
class UniqueColumnInspector
{
    /** @var array<string, list<list<string>>> */
    private array $constraintCache = [];

    /** @var array<string, list<array{name: string|null, columns: list<string>, type: string|null, unique: bool, primary: bool}>> */
    private array $indexCache = [];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Forgets every memoized table. Being a singleton, the inspector would
     * otherwise carry one run's schema into the next run in the same process.
     */
    public function reset(): void
    {
        $this->constraintCache = [];
        $this->indexCache = [];
    }

    /**
     * Every unique-or-primary constraint on the table, as its ordered
     * column tuple. A single-column constraint is a one-element tuple.
     * Memoized per (connection, table) for the instance lifetime.
     *
     * @return list<list<string>>
     */
    public function uniqueConstraints(string $table, ?string $connection = null): array
    {
        $cacheKey = ($connection ?? '').'.'.$table;

        if (isset($this->constraintCache[$cacheKey])) {
            return $this->constraintCache[$cacheKey];
        }

        $indexes = $this->indexes($table, $connection);

        $tuples = [];

        foreach ($indexes as $index) {
            if (! ($index['unique'] === true || $index['primary'] === true)) {
                continue;
            }

            $tuple = $index['columns'];

            // An index made only of expressions (unique on lower(email),
            // say) lists no columns. An empty tuple constrains no column,
            // so it is left out rather than offered as a paging identity.
            if ($tuple === []) {
                continue;
            }

            $key = implode("\x1f", $tuple);

            $tuples[$key] = $tuple;
        }

        return $this->constraintCache[$cacheKey] = array_values($tuples);
    }

    /**
     * The table's primary key as its ordered column tuple (composite-aware),
     * or null when the table declares no primary key — see
     * docs/design/engine-hardening/spec §R7 Paging identity, source 1.
     *
     * @return list<string>|null
     */
    public function primaryKey(string $table, ?string $connection = null): ?array
    {
        foreach ($this->indexes($table, $connection) as $index) {
            if ($index['primary'] === true) {
                return $index['columns'];
            }
        }

        return null;
    }

    /**
     * Raw getIndexes() result, memoized per (connection, table) so
     * uniqueConstraints() and primaryKey() together never issue more than
     * one schema query per table.
     *
     * @return list<array{name: string|null, columns: list<string>, type: string|null, unique: bool, primary: bool}>
     */
    private function indexes(string $table, ?string $connection = null): array
    {
        $cacheKey = ($connection ?? '').'.'.$table;

        if (isset($this->indexCache[$cacheKey])) {
            return $this->indexCache[$cacheKey];
        }

        /** @var list<array{name: string|null, columns: list<string>, type: string|null, unique: bool, primary: bool}> $indexes */
        $indexes = $this->db->connection($connection)->getSchemaBuilder()->getIndexes($table);

        return $this->indexCache[$cacheKey] = $indexes;
    }

    /**
     * The subset of uniqueConstraints($table) that includes at least one
     * of $declaredColumns — the constraints a sanitize of those columns
     * can actually violate.
     *
     * @param  list<string>  $declaredColumns
     * @return list<list<string>>
     */
    public function constraintsAffecting(string $table, array $declaredColumns, ?string $connection = null): array
    {
        return array_values(array_filter(
            $this->uniqueConstraints($table, $connection),
            fn (array $tuple): bool => array_intersect($tuple, $declaredColumns) !== []
        ));
    }
}
