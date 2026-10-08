<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Grammar;

/**
 * Builds one batched `UPDATE ... SET col = CASE ... END WHERE ...`
 * statement for a sub-batch of a chunk — see SanitizationRunner::batchUpdate().
 * Pure SQL text and bindings, no I/O, so the shape is unit-testable per
 * driver grammar.
 *
 * PostgreSQL types every bound value inside a CASE as text when nothing
 * else gives it a type, and text has no assignment cast to integer, date,
 * boolean, json, uuid and most other types (SQLSTATE 42804). On PostgreSQL
 * each value placeholder is therefore wrapped as `CAST(? AS <column type>)`,
 * with the type the schema reports for that column. Every other driver gets
 * the unchanged bare `?`.
 *
 * MySQL and MariaDB set a column declared `ON UPDATE CURRENT_TIMESTAMP` to
 * the current time on any UPDATE of its row that does not assign it. Such a
 * column, when it is not one of fields(), is assigned to itself so it keeps
 * the value it had.
 *
 * Internal to the package.
 */
final class BatchUpdateStatement
{
    /**
     * @param  list<string>  $identityColumns
     * @param  list<string>  $columns
     * @param  list<array{identity: array<string, mixed>, values: array<string, mixed>}>  $rowsToWrite
     * @param  array<string, string>  $valueTypes  column => SQL type to cast each bound value to
     * @param  list<string>  $keepColumns  columns the database would rewrite on any UPDATE (MySQL's ON UPDATE CURRENT_TIMESTAMP), each assigned to itself so it keeps its value; a column in $columns is written as usual
     * @return array{0: string, 1: list<mixed>}
     */
    public static function build(Grammar $grammar, string $table, array $identityColumns, array $columns, array $rowsToWrite, array $valueTypes = [], array $keepColumns = []): array
    {
        $wrappedTable = $grammar->wrapTable($table);
        $keepClauses = [];

        foreach ($keepColumns as $column) {
            if (! in_array($column, $columns, true)) {
                $keepClauses[] = $grammar->wrap($column).' = '.$grammar->wrap($column);
            }
        }

        if (count($identityColumns) === 1) {
            $id = $identityColumns[0];
            $wrappedId = $grammar->wrap($id);

            $setClauses = [];
            $bindings = [];

            foreach ($columns as $column) {
                $whenClauses = [];
                $placeholder = self::valuePlaceholder($valueTypes, $column);

                foreach ($rowsToWrite as $row) {
                    $whenClauses[] = 'WHEN ? THEN '.$placeholder;
                    $bindings[] = $row['identity'][$id];
                    $bindings[] = SanitizationRunner::bindableValue($row['values'][$column]);
                }

                $setClauses[] = sprintf(
                    '%s = CASE %s %s END',
                    $grammar->wrap($column),
                    $wrappedId,
                    implode(' ', $whenClauses)
                );
            }

            $idValues = array_map(fn (array $row): mixed => $row['identity'][$id], $rowsToWrite);
            $placeholders = implode(', ', array_fill(0, count($idValues), '?'));

            $sql = sprintf(
                'UPDATE %s SET %s WHERE %s IN (%s)',
                $wrappedTable,
                implode(', ', [...$setClauses, ...$keepClauses]),
                $wrappedId,
                $placeholders
            );

            return [$sql, [...$bindings, ...$idValues]];
        }

        $setClauses = [];
        $bindings = [];

        foreach ($columns as $column) {
            $whenClauses = [];
            $placeholder = self::valuePlaceholder($valueTypes, $column);

            foreach ($rowsToWrite as $row) {
                $conditions = [];

                foreach ($identityColumns as $idColumn) {
                    $conditions[] = $grammar->wrap($idColumn).' = ?';
                    $bindings[] = $row['identity'][$idColumn];
                }

                $whenClauses[] = 'WHEN '.implode(' AND ', $conditions).' THEN '.$placeholder;
                $bindings[] = SanitizationRunner::bindableValue($row['values'][$column]);
            }

            $setClauses[] = sprintf(
                '%s = CASE %s END',
                $grammar->wrap($column),
                implode(' ', $whenClauses)
            );
        }

        // The row selector stays an OR of equality groups: PostgreSQL plans
        // it as a BitmapOr of index lookups, one per row.
        $orClauses = [];

        foreach ($rowsToWrite as $row) {
            $conditions = [];

            foreach ($identityColumns as $idColumn) {
                $conditions[] = $grammar->wrap($idColumn).' = ?';
                $bindings[] = $row['identity'][$idColumn];
            }

            $orClauses[] = '('.implode(' AND ', $conditions).')';
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $wrappedTable,
            implode(', ', [...$setClauses, ...$keepClauses]),
            implode(' OR ', $orClauses)
        );

        return [$sql, $bindings];
    }

    /**
     * The cast type per declared column for the given driver: on PostgreSQL
     * the column's native type without its length or precision, for every
     * column whose type is known; on every other driver none at all.
     *
     * @param  array<string, ColumnConstraints>  $constraints
     * @return array<string, string>
     */
    public static function valueTypesFor(string $driver, array $constraints): array
    {
        if ($driver !== 'pgsql') {
            return [];
        }

        $types = [];

        foreach ($constraints as $column => $constraint) {
            $type = $constraint->nativeType === null ? null : self::postgresCastType($constraint->nativeType);

            if ($type !== null) {
                $types[$column] = $type;
            }
        }

        return $types;
    }

    /**
     * The cast target for a PostgreSQL column type as format_type() reports
     * it (getColumns()'s `type`). The length or precision is dropped
     * (`character varying(20)` becomes `character varying`, `numeric(8,2)`
     * becomes `numeric`), because an explicit cast to a length silently
     * truncates; the column's own assignment then checks the length or
     * rounds exactly as it would for a plain bound value. Two types mean
     * length 1 without their length (`character` is character(1), `bit` is
     * bit(1)), so they are cast to their unbounded forms instead: `bpchar`
     * and `bit varying`, which the column then checks the same way. A quoted
     * type name is kept as it is. Anything that is not a plain type name
     * gives null, and the value is then bound without a cast.
     */
    public static function postgresCastType(string $type): ?string
    {
        $type = trim($type);

        if (! str_contains($type, '"')) {
            $type = trim((string) preg_replace(['/\(\s*\d+\s*(?:,\s*\d+\s*)?\)/', '/\s+/'], ['', ' '], $type));
            $type = (string) preg_replace(['/^(?:character|char)((?:\[\])*)$/i', '/^bit((?:\[\])*)$/i'], ['bpchar$1', 'bit varying$1'], $type);
        }

        if (preg_match('/^(?:[A-Za-z_][A-Za-z0-9_$ ]*|"[^"]+")(?:\.(?:[A-Za-z_][A-Za-z0-9_$]*|"[^"]+"))?(?:\[\])*$/', $type) !== 1) {
            return null;
        }

        return $type;
    }

    /**
     * @param  array<string, string>  $valueTypes
     */
    private static function valuePlaceholder(array $valueTypes, string $column): string
    {
        return isset($valueTypes[$column]) ? 'CAST(? AS '.$valueTypes[$column].')' : '?';
    }
}
