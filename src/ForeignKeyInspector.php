<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;

/**
 * The single reader of foreign-key metadata (docs/design/referenced-identifier-structured-column-sanitization/spec,
 * "ForeignKeyInspector"). SchemaGuard uses it for the v1 outbound and inbound
 * rejections; ReferencedColumnGuard uses edgesTouching() to refuse a run whose
 * mirror group does not cover every foreign key that touches it. One class means
 * one canonical-name and table-prefix normalisation, and one table-listing sweep
 * per connection per run.
 *
 * A table is identified by its schema and its physical name. The schema is
 * null for the connection's default schema, so "users" and "public.users"
 * are the same table on PostgreSQL while "hr.users" is another. A model's
 * table name gets the connection prefix added; a name read from the
 * database (the table listing, a foreign key's target) is already physical
 * and is used as it is, so a table without the prefix is still found.
 *
 * Every cache is keyed by connection name plus that identity so a second
 * connection's or a second schema's tables never poison or mask the first's.
 */
final class ForeignKeyInspector
{
    /** @var array<string, list<string>> */
    private array $outboundCache = [];

    /** @var array<string, array<string, string>> */
    private array $outboundTargets = [];

    /**
     * getForeignKeys() results keyed by table key, shared between the
     * outbound-FK lookup and the inbound-FK global sweep so a table's
     * foreign keys are fetched from the schema at most once.
     *
     * @var array<string, list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}>>
     */
    private array $foreignKeysCache = [];

    /**
     * Per-connection index of inbound references: connection name => target
     * table key => column => canonical name of the referencing table. Built
     * lazily, at most once per connection, by sweeping every table in that
     * connection's schema a single time.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private array $inboundIndex = [];

    /** @var array<string, true> */
    private array $inboundIndexBuilt = [];

    /** @var array<string, list<array{0: string|null, 1: string}>> */
    private array $tableListings = [];

    /** @var array<string, list<array{child_table: string, child_columns: list<string>, parent_table: string, parent_columns: list<string>, on_update: string, child_key: string, parent_key: string}>> */
    private array $allEdges = [];

    /** @var array<string, string|null> */
    private array $defaultSchemas = [];

    /**
     * Forgets every cache. Being a singleton, the inspector would otherwise
     * carry one run's foreign keys into the next run in the same process, so
     * a foreign key added in between would go unseen.
     */
    public function reset(): void
    {
        $this->outboundCache = [];
        $this->outboundTargets = [];
        $this->foreignKeysCache = [];
        $this->inboundIndex = [];
        $this->inboundIndexBuilt = [];
        $this->tableListings = [];
        $this->allEdges = [];
        $this->defaultSchemas = [];
    }

    /**
     * @return list<string>
     */
    public function outboundForeignKeyColumns(Connection $connection, string $table): array
    {
        $identity = $this->modelTable($connection, $table);
        $cacheKey = $this->identityKey($connection, $identity);

        if (isset($this->outboundCache[$cacheKey])) {
            return $this->outboundCache[$cacheKey];
        }

        $columns = [];

        foreach ($this->rawForeignKeys($connection, $identity) as $entry) {
            $target = $this->displayName($connection, $this->physicalTable($connection, $entry['foreign_schema'], $entry['foreign_table']));

            foreach ($entry['columns'] as $column) {
                $columns[] = $column;
                $this->outboundTargets[$cacheKey][$column] = $target;
            }
        }

        return $this->outboundCache[$cacheKey] = array_values(array_unique($columns));
    }

    /**
     * The canonical name of the table an outbound foreign-key column references.
     */
    public function outboundTarget(Connection $connection, string $table, string $column): string
    {
        $this->outboundForeignKeyColumns($connection, $table);

        return $this->outboundTargets[$this->identityKey($connection, $this->modelTable($connection, $table))][$column];
    }

    /**
     * Every column referenced by a foreign key in the connection's schema, mapped to a referencing table;
     * a self-referencing key counts, so the referencing table can be $table itself.
     *
     * @return array<string, string>
     */
    public function inboundReferencedColumns(Connection $connection, string $table): array
    {
        $this->ensureInboundIndex($connection);

        $targetKey = $this->identityKey($connection, $this->modelTable($connection, $table));

        return $this->inboundIndex[$connection->getName()][$targetKey] ?? [];
    }

    /**
     * Every FK edge, in the connection's schema, with $table.$column on either side,
     * self-referencing (same-table) edges included. Composite FKs are returned whole;
     * callers pair child_columns[i] with parent_columns[i]. child_key and
     * parent_key identify each table exactly (see tableKey()); child_table and
     * parent_table are the canonical names used in messages.
     *
     * @return list<array{child_table: string, child_columns: list<string>, parent_table: string, parent_columns: list<string>, on_update: string, child_key: string, parent_key: string}>
     */
    public function edgesTouching(Connection $connection, string $table, string $column): array
    {
        $key = $this->tableKey($connection, $table);

        $edges = [];

        foreach ($this->allEdges($connection) as $edge) {
            $child = $edge['child_key'] === $key && in_array($column, $edge['child_columns'], true);
            $parent = $edge['parent_key'] === $key && in_array($column, $edge['parent_columns'], true);

            if ($child || $parent) {
                $edges[] = $edge;
            }
        }

        return $edges;
    }

    /**
     * The canonical, comparable name of a model's table: the connection
     * prefix is not shown, and the schema is shown only when it is not the
     * connection's default schema (e.g. "public.wp_users" -> "users",
     * "hr.wp_users" -> "hr.users" under prefix "wp_"). Used in messages and
     * wherever a table name is compared with a foreign-key edge's names.
     */
    public function canonicalTableName(Connection $connection, string $name): string
    {
        return $this->displayName($connection, $this->modelTable($connection, $name));
    }

    /**
     * An exact identity for a model's table on its connection: schema plus
     * physical name. Two tables whose canonical names coincide (an
     * unprefixed physical table and a prefixed one) still get different keys.
     */
    public function tableKey(Connection $connection, string $name): string
    {
        return $this->identityKey($connection, $this->modelTable($connection, $name));
    }

    /**
     * One getTableListing() sweep per connection, shared by the inbound
     * index and the all-edges list. Each entry is the listed table's
     * identity: its schema (null for the default schema) and physical name.
     *
     * @return list<array{0: string|null, 1: string}>
     */
    private function tableListing(Connection $connection): array
    {
        $connectionKey = $connection->getName();

        if (isset($this->tableListings[$connectionKey])) {
            return $this->tableListings[$connectionKey];
        }

        /** @var list<string> $tableListing */
        $tableListing = $connection->getSchemaBuilder()->getTableListing();

        $identities = [];

        foreach ($tableListing as $listed) {
            [$schema, $table] = $this->splitName($listed);

            $identities[] = $this->physicalTable($connection, $schema, $table);
        }

        return $this->tableListings[$connectionKey] = $identities;
    }

    /**
     * @return list<array{child_table: string, child_columns: list<string>, parent_table: string, parent_columns: list<string>, on_update: string, child_key: string, parent_key: string}>
     */
    private function allEdges(Connection $connection): array
    {
        $connectionKey = $connection->getName();

        if (isset($this->allEdges[$connectionKey])) {
            return $this->allEdges[$connectionKey];
        }

        $edges = [];

        foreach ($this->tableListing($connection) as $child) {
            foreach ($this->rawForeignKeys($connection, $child) as $entry) {
                $parent = $this->physicalTable($connection, $entry['foreign_schema'], $entry['foreign_table']);

                $edges[] = [
                    'child_table' => $this->displayName($connection, $child),
                    'child_columns' => $entry['columns'],
                    'parent_table' => $this->displayName($connection, $parent),
                    'parent_columns' => $entry['foreign_columns'],
                    'on_update' => $entry['on_update'],
                    'child_key' => $this->identityKey($connection, $child),
                    'parent_key' => $this->identityKey($connection, $parent),
                ];
            }
        }

        return $this->allEdges[$connectionKey] = $edges;
    }

    /**
     * Fetches and caches the getForeignKeys() result for a physical table,
     * so repeated lookups (from the outbound check and the inbound global
     * sweep alike) never re-query the schema for the same table. The query
     * runs with the connection prefix switched off, since the name is
     * already physical. A foreign key declared without its referenced
     * columns has them resolved to the referenced table's primary key.
     *
     * @param  array{0: string|null, 1: string}  $identity
     * @return list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}>
     */
    private function rawForeignKeys(Connection $connection, array $identity): array
    {
        $cacheKey = $this->identityKey($connection, $identity);

        if (isset($this->foreignKeysCache[$cacheKey])) {
            return $this->foreignKeysCache[$cacheKey];
        }

        /** @var list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}> $foreignKeys */
        $foreignKeys = $this->withoutPrefix(
            $connection,
            fn () => $connection->getSchemaBuilder()->getForeignKeys($this->qualifiedName($identity)),
        );

        foreach ($foreignKeys as $index => $entry) {
            if (in_array('', $entry['foreign_columns'], true)) {
                $foreignKeys[$index]['foreign_columns'] = $this->implicitForeignColumns($connection, $identity, $entry);
            }
        }

        return $this->foreignKeysCache[$cacheKey] = $foreignKeys;
    }

    /**
     * SQLite reports a foreign key declared as `REFERENCES parent` (no
     * column list) with an empty referenced column; such a key references
     * the parent's primary key.
     *
     * @param  array{0: string|null, 1: string}  $identity
     * @param  array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}  $entry
     * @return list<string>
     */
    private function implicitForeignColumns(Connection $connection, array $identity, array $entry): array
    {
        $parent = $this->physicalTable($connection, $entry['foreign_schema'], $entry['foreign_table']);

        /** @var list<array{name: string, columns: list<string>, type: string|null, unique: bool, primary: bool}> $indexes */
        $indexes = $this->withoutPrefix(
            $connection,
            fn () => $connection->getSchemaBuilder()->getIndexes($this->qualifiedName($parent)),
        );

        foreach ($indexes as $index) {
            if ($index['primary'] && count($index['columns']) === count($entry['columns'])) {
                return $index['columns'];
            }
        }

        throw UnsafeColumnException::unresolvedForeignKey(
            $this->displayName($connection, $identity),
            $entry['columns'],
            $this->displayName($connection, $parent),
        );
    }

    /**
     * Builds the connection-wide inbound-reference index at most once per
     * (instance, connection): a single table-listing sweep, and a
     * single getForeignKeys() call per listed table (shared with the
     * outbound cache via rawForeignKeys()), rather than repeating both per
     * checked table. Indexed per connection name so a second connection's
     * sweep never mixes with or is skipped in favor of the first's.
     */
    private function ensureInboundIndex(Connection $connection): void
    {
        $connectionKey = $connection->getName();

        if (isset($this->inboundIndexBuilt[$connectionKey])) {
            return;
        }

        foreach ($this->tableListing($connection) as $listed) {
            $referencing = $this->displayName($connection, $listed);

            foreach ($this->rawForeignKeys($connection, $listed) as $entry) {
                $targetKey = $this->identityKey($connection, $this->physicalTable($connection, $entry['foreign_schema'], $entry['foreign_table']));

                foreach ($entry['foreign_columns'] as $column) {
                    $this->inboundIndex[$connectionKey][$targetKey][$column] = $referencing;
                }
            }
        }

        $this->inboundIndexBuilt[$connectionKey] = true;
    }

    /**
     * The identity of a model's table: Laravel adds the connection prefix
     * to the table part of the name, schema-qualified or not.
     *
     * @return array{0: string|null, 1: string}
     */
    private function modelTable(Connection $connection, string $name): array
    {
        [$schema, $table] = $this->splitName($name);

        return $this->physicalTable($connection, $schema, $connection->getTablePrefix().$table);
    }

    /**
     * The identity of a table whose name was read from the database.
     *
     * @return array{0: string|null, 1: string}
     */
    private function physicalTable(Connection $connection, ?string $schema, string $table): array
    {
        if ($schema !== null && $schema === $this->defaultSchema($connection)) {
            $schema = null;
        }

        return [$schema, $table];
    }

    /**
     * The schema an unqualified table name resolves to, the same one the
     * schema builder's own queries use: current_schema() on PostgreSQL (the
     * first existing schema on the search path), the connection's database
     * on MySQL and MariaDB, schema_name() on SQL Server and "main" on
     * SQLite. Null for any other connection, which leaves names as listed.
     */
    private function defaultSchema(Connection $connection): ?string
    {
        $connectionKey = $connection->getName();

        if (array_key_exists($connectionKey, $this->defaultSchemas)) {
            return $this->defaultSchemas[$connectionKey];
        }

        $schema = match (true) {
            $connection instanceof PostgresConnection => $connection->scalar('select current_schema()'),
            $connection instanceof MySqlConnection => $connection->getDatabaseName(),
            $connection instanceof SqlServerConnection => $connection->scalar('select schema_name()'),
            $connection instanceof SQLiteConnection => 'main',
            default => null,
        };

        return $this->defaultSchemas[$connectionKey] = is_string($schema) && $schema !== '' ? $schema : null;
    }

    /**
     * @param  array{0: string|null, 1: string}  $identity
     */
    private function identityKey(Connection $connection, array $identity): string
    {
        return $connection->getName().'.'.($identity[0] === null ? '' : $identity[0].'.').$identity[1];
    }

    /**
     * @param  array{0: string|null, 1: string}  $identity
     */
    private function displayName(Connection $connection, array $identity): string
    {
        $table = $identity[1];
        $prefix = $connection->getTablePrefix();

        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            $table = substr($table, strlen($prefix));
        }

        return $identity[0] === null ? $table : $identity[0].'.'.$table;
    }

    /**
     * @param  array{0: string|null, 1: string}  $identity
     */
    private function qualifiedName(array $identity): string
    {
        return $identity[0] === null ? $identity[1] : $identity[0].'.'.$identity[1];
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function splitName(string $name): array
    {
        $position = strpos($name, '.');

        return $position === false
            ? [null, $name]
            : [substr($name, 0, $position), substr($name, $position + 1)];
    }

    /**
     * Runs a schema-builder call with the connection prefix switched off,
     * for a name that is already physical; restores the prefix afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutPrefix(Connection $connection, callable $callback): mixed
    {
        $prefix = $connection->getTablePrefix();

        if ($prefix === '') {
            return $callback();
        }

        $connection->setTablePrefix('');

        try {
            return $callback();
        } finally {
            $connection->setTablePrefix($prefix);
        }
    }
}
