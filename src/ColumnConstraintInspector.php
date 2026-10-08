<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\DatabaseManager;

/**
 * Introspects a table's column-level constraints (nullability, length,
 * allowed set, type family) so ConstraintValidator can validate a generated
 * replacement value before it is written — see
 * docs/design/engine-hardening/spec §R6.1 / §Driver matrix and schema
 * source. Memoized per (connection, table), the same way UniqueColumnInspector
 * memoizes uniqueConstraints().
 *
 * Not final so a Feature test can bind a subclass that stubs constraintsFor()
 * for a driver/length combination the SQLite test harness cannot itself
 * produce (mirrors UniqueColumnInspector).
 */
class ColumnConstraintInspector
{
    /** @var array<string, array<string, ColumnConstraints>> */
    private array $cache = [];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array<string, ColumnConstraints>
     */
    public function constraintsFor(string $table, ?string $connection = null): array
    {
        $cacheKey = ($connection ?? '').'.'.$table;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $conn = $this->db->connection($connection);

        /** @var list<array{name: string, type: string, type_name: string, nullable: bool}> $columns */
        $columns = $conn->getSchemaBuilder()->getColumns($table);
        $driver = $conn->getDriverName();

        $ddl = null;

        if ($driver === 'sqlite') {
            $ddlResult = $conn->scalar(
                'select "sql" from sqlite_master where type = ? and name = ?',
                ['table', $conn->getTablePrefix().$table]
            );

            $ddl = is_string($ddlResult) ? $ddlResult : null;
        }

        return $this->cache[$cacheKey] = $this->parse($driver, $columns, $ddl);
    }

    /**
     * The pure parsing step: schema rows (in getColumns()'s shape) + an
     * optional SQLite DDL string, in — a column => ColumnConstraints map,
     * out. No I/O — every driver branch is unit-testable with stubbed input.
     *
     * @param  list<array{name: string, type: string, type_name: string, nullable: bool}>  $columns
     * @return array<string, ColumnConstraints>
     */
    public function parse(string $driver, array $columns, ?string $ddl = null): array
    {
        $result = [];

        foreach ($columns as $row) {
            $name = $row['name'];
            $type = strtolower($row['type']);
            $typeName = strtolower($row['type_name']);
            $nullable = (bool) $row['nullable'];

            if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
                $result[$name] = new ColumnConstraints($name, 'other', null, null, $nullable);

                continue;
            }

            $family = $this->familyFor($type, $typeName);
            $maxLength = $this->maxLengthFor($driver, $type);
            $allowed = $this->allowedFor($driver, $type, $name, $ddl);

            $result[$name] = new ColumnConstraints($name, $family, $maxLength, $allowed, $nullable);
        }

        return $result;
    }

    private function familyFor(string $type, string $typeName): string
    {
        if ($type === 'tinyint(1)' || in_array($typeName, ['bool', 'boolean'], true)) {
            return 'boolean';
        }

        if (in_array($typeName, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'int2', 'int4', 'int8', 'serial', 'bigserial', 'smallserial'], true)) {
            return 'integer';
        }

        if (in_array($typeName, ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8', 'double precision'], true)) {
            return 'decimal';
        }

        if (in_array($typeName, ['date', 'datetime', 'timestamp', 'timestamptz', 'time', 'timetz', 'year'], true)) {
            return 'datetime';
        }

        if (in_array($typeName, ['json', 'jsonb'], true)) {
            return 'json';
        }

        if ($typeName === 'set') {
            return 'set';
        }

        if (in_array($typeName, ['varchar', 'char', 'character varying', 'character', 'bpchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'uuid', 'citext'], true)) {
            return 'string';
        }

        if (in_array($typeName, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bytea'], true)) {
            return 'binary';
        }

        return 'other';
    }

    private function maxLengthFor(string $driver, string $type): ?int
    {
        if ($driver === 'sqlite') {
            // SQLite never enforces a declared length — validating it here
            // would create a check the database itself never had.
            return null;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m) === 1) {
                return (int) $m[1];
            }

            return null;
        }

        // pgsql
        if (preg_match('/^(?:character varying|varchar|character|char|bpchar)\((\d+)\)/', $type, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function allowedFor(string $driver, string $type, string $column, ?string $ddl): ?array
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (preg_match('/^(?:enum|set)\((.*)\)$/s', $type, $m) === 1) {
                return $this->parseQuotedList($m[1]);
            }

            return null;
        }

        if ($driver === 'pgsql') {
            // Laravel emits a Postgres enum as either a CHECK constraint or
            // a native CREATE TYPE ... AS ENUM, neither of which
            // getColumns() exposes — documented gap, not validated here.
            return null;
        }

        // sqlite: the allowed set (an enum's CHECK constraint) is only
        // visible via the table's own DDL, not via getColumns().
        if ($ddl === null) {
            return null;
        }

        $quotedColumn = preg_quote($column, '/');
        $pattern = '/["`]?'.$quotedColumn.'["`]?\s+[^,]*?check\s*\(\s*["`]?'.$quotedColumn.'["`]?\s+in\s*\(((?:\s*\'(?:[^\']|\'\')*\'\s*,?)+)\)\s*\)/is';

        if (preg_match($pattern, $ddl, $m) === 1) {
            return $this->parseQuotedList($m[1]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function parseQuotedList(string $list): array
    {
        preg_match_all("/'((?:[^']|'')*)'/", $list, $matches);

        return array_map(
            fn (string $value): string => str_replace("''", "'", $value),
            $matches[1]
        );
    }
}
