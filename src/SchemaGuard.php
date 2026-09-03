<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\SchemaGuardContract;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;

class SchemaGuard implements SchemaGuardContract
{
    /** Every cache below is keyed by "<connection-name>.<canonical-table>" so a second connection's schema never poisons or is masked by the first's. */

    /** @var array<string, list<string>> */
    private array $columnCache = [];

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

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function assertSafe(Sanitizer $sanitizer, Model|string $model): void
    {
        $modelClass = is_object($model) ? $model::class : $model;
        $instance = is_object($model) ? $model : app($modelClass);

        if (! $instance instanceof Model) {
            throw InvalidConfigurationException::invalidModelClass($modelClass);
        }

        $columns = array_keys($sanitizer->fields());

        if ($columns === []) {
            return;
        }

        $table = $instance->getTable();
        $connection = $this->db->connection($instance->getConnectionName());

        $existing = $this->tableColumns($connection, $table);

        foreach ($columns as $column) {
            if (! in_array($column, $existing, true)) {
                throw UnsafeColumnException::unknownColumn($modelClass, $column, $sanitizer::class, $table);
            }
        }

        $outbound = $this->outboundForeignKeyColumns($connection, $table);
        $cacheKey = $this->cacheKey($connection, $table);

        foreach ($columns as $column) {
            if (in_array($column, $outbound, true)) {
                throw UnsafeColumnException::outboundForeignKey(
                    $modelClass,
                    $column,
                    $sanitizer::class,
                    $table,
                    $this->outboundTargets[$cacheKey][$column]
                );
            }
        }

        $inbound = $this->inboundReferencedColumns($connection, $table);

        foreach ($columns as $column) {
            if (array_key_exists($column, $inbound)) {
                throw UnsafeColumnException::inboundReference(
                    $modelClass,
                    $column,
                    $sanitizer::class,
                    $table,
                    $inbound[$column]
                );
            }
        }

        // The primary key drives chunkById()'s paging/ordering (R5.2); rewriting
        // it mid-run would corrupt the chunk cursor for that same read. Checked
        // last so a primary key that is also an inbound-referenced column (the
        // common case) still surfaces the more specific FK message above.
        $primaryKey = $instance->getKeyName();

        if (in_array($primaryKey, $columns, true)) {
            throw UnsafeColumnException::primaryKey($modelClass, $primaryKey, $sanitizer::class, $table);
        }
    }

    /**
     * @return list<string>
     */
    private function tableColumns(Connection $connection, string $table): array
    {
        $cacheKey = $this->cacheKey($connection, $table);

        if (isset($this->columnCache[$cacheKey])) {
            return $this->columnCache[$cacheKey];
        }

        /** @var list<array{name: string, type: string, type_name: string, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation: array<string, mixed>|null}> $columns */
        $columns = $connection->getSchemaBuilder()->getColumns($table);

        return $this->columnCache[$cacheKey] = array_column($columns, 'name');
    }

    /**
     * @return list<string>
     */
    private function outboundForeignKeyColumns(Connection $connection, string $table): array
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
     * @return array<string, string>
     */
    private function inboundReferencedColumns(Connection $connection, string $table): array
    {
        $this->ensureInboundIndex($connection);

        $connectionKey = $connection->getName();
        $canonical = $this->canonicalTableName($connection, $table);

        return $this->inboundIndex[$connectionKey][$canonical] ?? [];
    }

    /**
     * Builds the connection-wide inbound-reference index at most once per
     * (instance, connection): a single getTableListing() sweep, and a
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

        /** @var list<string> $tableListing */
        $tableListing = $connection->getSchemaBuilder()->getTableListing();

        foreach ($tableListing as $listedTable) {
            $physicalShort = $this->canonicalTableName($connection, $listedTable);

            $foreignKeys = $this->rawForeignKeys($connection, $physicalShort);

            foreach ($foreignKeys as $entry) {
                $targetShort = $this->canonicalTableName($connection, $entry['foreign_table']);

                if ($targetShort === $physicalShort) {
                    continue;
                }

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
    private function canonicalTableName(Connection $connection, string $name): string
    {
        $short = $this->shortTableName($name);

        $prefix = $connection->getTablePrefix();

        return $prefix !== '' && str_starts_with($short, $prefix)
            ? substr($short, strlen($prefix))
            : $short;
    }
}
