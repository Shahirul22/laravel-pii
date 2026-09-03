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

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

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

        /** @var list<array{name: string|null, columns: list<string>, type: string|null, unique: bool, primary: bool}> $indexes */
        $indexes = $this->db->connection($connection)->getSchemaBuilder()->getIndexes($table);

        $tuples = [];

        foreach ($indexes as $index) {
            if (! ($index['unique'] === true || $index['primary'] === true)) {
                continue;
            }

            $tuple = $index['columns'];

            $key = implode("\x1f", $tuple);

            $tuples[$key] = $tuple;
        }

        return $this->constraintCache[$cacheKey] = array_values($tuples);
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
