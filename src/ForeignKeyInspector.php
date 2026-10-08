<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Connection;

/**
 * The single reader of foreign-key metadata (docs/design/referenced-identifier-structured-column-sanitization/spec,
 * "ForeignKeyInspector"). SchemaGuard uses it for the v1 outbound and inbound
 * rejections; ReferencedColumnGuard uses edgesTouching() to refuse a run whose
 * mirror group does not cover every foreign key that touches it. One class means
 * one canonical-name and table-prefix normalisation, and one table-listing sweep
 * per connection per run.
 *
 * Every cache is keyed by "<connection-name>.<canonical-table>" (or the bare
 * connection name for a connection-wide cache) so a second connection's schema
 * never poisons or is masked by the first's.
 */
final class ForeignKeyInspector
{
    /** @var array<string, list<string>> */
    private array $outboundCache = [];

    /** @var array<string, array<string, string>> */
    private array $outboundTargets = [];

    /**
     * Raw getForeignKeys() results keyed by cache key, shared between the
     * outbound-FK lookup and the inbound-FK global sweep so a table's
     * foreign keys are fetched from the schema at most once.
     *
     * @var array<string, list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}>>
     */
    private array $foreignKeysCache = [];

    /**
     * Per-connection index of inbound references: connection name => target
     * short table name => column => referencing physical table name. Built
     * lazily, at most once per connection, by sweeping every table in that
     * connection's schema a single time.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private array $inboundIndex = [];

    /** @var array<string, true> */
    private array $inboundIndexBuilt = [];

    /** @var array<string, list<string>> */
    private array $tableListings = [];

    /** @var array<string, list<array{child_table: string, child_columns: list<string>, parent_table: string, parent_columns: list<string>, on_update: string}>> */
    private array $allEdges = [];

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
    }

    /**
     * @return list<string>
     */
    public function outboundForeignKeyColumns(Connection $connection, string $table): array
    {
        $cacheKey = $this->cacheKey($connection, $table);

        if (isset($this->outboundCache[$cacheKey])) {
            return $this->outboundCache[$cacheKey];
        }

        $canonical = $this->canonicalTableName($connection, $table);

        $foreignKeys = $this->rawForeignKeys($connection, $canonical);

        $columns = [];

        foreach ($foreignKeys as $entry) {
            foreach ($entry['columns'] as $column) {
                $columns[] = $column;
                $this->outboundTargets[$cacheKey][$column] = $this->shortTableName($entry['foreign_table']);
            }
        }

        return $this->outboundCache[$cacheKey] = array_values(array_unique($columns));
    }

    /**
     * The short name of the table an outbound foreign-key column references.
     */
    public function outboundTarget(Connection $connection, string $table, string $column): string
    {
        $this->outboundForeignKeyColumns($connection, $table);

        return $this->outboundTargets[$this->cacheKey($connection, $table)][$column];
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

        $connectionKey = $connection->getName();
        $canonical = $this->canonicalTableName($connection, $table);

        return $this->inboundIndex[$connectionKey][$canonical] ?? [];
    }

    /**
     * Every FK edge, in the connection's schema, with $table.$column on either side,
     * self-referencing (same-table) edges included. Composite FKs are returned whole;
     * callers pair child_columns[i] with parent_columns[i].
     *
     * @return list<array{child_table: string, child_columns: list<string>, parent_table: string, parent_columns: list<string>, on_update: string}>
     */
    public function edgesTouching(Connection $connection, string $table, string $column): array
    {
        $canonical = $this->canonicalTableName($connection, $table);

        $edges = [];

        foreach ($this->allEdges($connection) as $edge) {
            $child = $edge['child_table'] === $canonical && in_array($column, $edge['child_columns'], true);
            $parent = $edge['parent_table'] === $canonical && in_array($column, $edge['parent_columns'], true);

            if ($child || $parent) {
                $edges[] = $edge;
            }
        }

        return $edges;
    }

    /**
     * Normalizes a table name to one canonical, comparable form: strip the
     * schema qualifier first (e.g. "public.wp_users" -> "wp_users"), then
     * strip the connection's table prefix from that short name (e.g.
     * "wp_users" -> "users"). Order matters — prefix-stripping a
     * schema-qualified string never matches, since the prefix is not at the
     * start of the qualified string. Used consistently everywhere a table
     * name is turned into an index/cache key, so all comparisons and cache
     * lookups address the same physical table.
     */
    public function canonicalTableName(Connection $connection, string $name): string
    {
        $short = $this->shortTableName($name);

        $prefix = $connection->getTablePrefix();

        return $prefix !== '' && str_starts_with($short, $prefix)
            ? substr($short, strlen($prefix))
            : $short;
    }

    /**
     * One getTableListing() sweep per connection, shared by the inbound
     * index and the all-edges list.
     *
     * @return list<string>
     */
    private function tableListing(Connection $connection): array
    {
        $connectionKey = $connection->getName();

        if (isset($this->tableListings[$connectionKey])) {
            return $this->tableListings[$connectionKey];
        }

        /** @var list<string> $tableListing */
        $tableListing = $connection->getSchemaBuilder()->getTableListing();

        return $this->tableListings[$connectionKey] = $tableListing;
    }

    /**
     * @return list<array{child_table: string, child_columns: list<string>, parent_table: string, parent_columns: list<string>, on_update: string}>
     */
    private function allEdges(Connection $connection): array
    {
        $connectionKey = $connection->getName();

        if (isset($this->allEdges[$connectionKey])) {
            return $this->allEdges[$connectionKey];
        }

        $edges = [];

        foreach ($this->tableListing($connection) as $listedTable) {
            $child = $this->canonicalTableName($connection, $listedTable);

            foreach ($this->rawForeignKeys($connection, $child) as $entry) {
                $edges[] = [
                    'child_table' => $child,
                    'child_columns' => $entry['columns'],
                    'parent_table' => $this->canonicalTableName($connection, $entry['foreign_table']),
                    'parent_columns' => $entry['foreign_columns'],
                    'on_update' => $entry['on_update'],
                ];
            }
        }

        return $this->allEdges[$connectionKey] = $edges;
    }

    /**
     * Fetches and caches the raw getForeignKeys() result for a physical
     * table, so repeated lookups (from the outbound check and the inbound
     * global sweep alike) never re-query the schema for the same table.
     *
     * @return list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}>
     */
    private function rawForeignKeys(Connection $connection, string $table): array
    {
        $cacheKey = $this->cacheKey($connection, $table);

        if (isset($this->foreignKeysCache[$cacheKey])) {
            return $this->foreignKeysCache[$cacheKey];
        }

        /** @var list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}> $foreignKeys */
        $foreignKeys = $connection->getSchemaBuilder()->getForeignKeys($table);

        return $this->foreignKeysCache[$cacheKey] = $foreignKeys;
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

        foreach ($this->tableListing($connection) as $listedTable) {
            $physicalShort = $this->canonicalTableName($connection, $listedTable);

            $foreignKeys = $this->rawForeignKeys($connection, $physicalShort);

            foreach ($foreignKeys as $entry) {
                $targetShort = $this->canonicalTableName($connection, $entry['foreign_table']);

                foreach ($entry['foreign_columns'] as $column) {
                    $this->inboundIndex[$connectionKey][$targetShort][$column] = $physicalShort;
                }
            }
        }

        $this->inboundIndexBuilt[$connectionKey] = true;
    }

    private function cacheKey(Connection $connection, string $table): string
    {
        return $connection->getName().'.'.$this->canonicalTableName($connection, $table);
    }

    private function shortTableName(string $name): string
    {
        $position = strrpos($name, '.');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
