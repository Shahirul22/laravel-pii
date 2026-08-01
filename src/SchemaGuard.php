<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\SchemaGuardContract;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;

class SchemaGuard implements SchemaGuardContract
{
    /** @var array<string, list<string>> */
    private array $columnCache = [];

    /** @var array<string, list<string>> */
    private array $outboundCache = [];

    /** @var array<string, array<string, string>> */
    private array $outboundTargets = [];

    /**
     * Raw getForeignKeys() results keyed by physical table name, shared between
     * the outbound-FK lookup and the inbound-FK global sweep so a table's
     * foreign keys are fetched from the schema at most once.
     *
     * @var array<string, list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}>>
     */
    private array $foreignKeysCache = [];

    /**
     * Connection-wide index of inbound references: target short table name =>
     * column => referencing physical table name. Built lazily, at most once,
     * by sweeping every table in the schema a single time.
     *
     * @var array<string, array<string, string>>
     */
    private array $inboundIndex = [];

    private bool $inboundIndexBuilt = false;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function assertSafe(Sanitizer $sanitizer, Model|string $model): void
    {
        $modelClass = is_object($model) ? $model::class : $model;
        $instance = is_object($model) ? $model : app($modelClass);

        assert($instance instanceof Model);

        $columns = array_keys($sanitizer->fields());

        if ($columns === []) {
            return;
        }

        $table = $instance->getTable();
        $connection = $this->db->connection();

        $existing = $this->tableColumns($connection, $table);

        foreach ($columns as $column) {
            if (! in_array($column, $existing, true)) {
                throw UnsafeColumnException::unknownColumn($modelClass, $column, $sanitizer::class, $table);
            }
        }

        $outbound = $this->outboundForeignKeyColumns($connection, $table);

        foreach ($columns as $column) {
            if (in_array($column, $outbound, true)) {
                throw UnsafeColumnException::outboundForeignKey(
                    $modelClass,
                    $column,
                    $sanitizer::class,
                    $table,
                    $this->outboundTargets[$table][$column]
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
    }

    /**
     * @return list<string>
     */
    private function tableColumns(Connection $connection, string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        /** @var list<array{name: string, type: string, type_name: string, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation: array<string, mixed>|null}> $columns */
        $columns = $connection->getSchemaBuilder()->getColumns($table);

        return $this->columnCache[$table] = array_column($columns, 'name');
    }

    /**
     * @return list<string>
     */
    private function outboundForeignKeyColumns(Connection $connection, string $table): array
    {
        if (isset($this->outboundCache[$table])) {
            return $this->outboundCache[$table];
        }

        $canonical = $this->canonicalTableName($connection, $table);

        $foreignKeys = $this->rawForeignKeys($connection, $canonical);

        $columns = [];

        foreach ($foreignKeys as $entry) {
            foreach ($entry['columns'] as $column) {
                $columns[] = $column;
                $this->outboundTargets[$table][$column] = $this->shortTableName($entry['foreign_table']);
            }
        }

        return $this->outboundCache[$table] = array_values(array_unique($columns));
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
        if (isset($this->foreignKeysCache[$table])) {
            return $this->foreignKeysCache[$table];
        }

        /** @var list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}> $foreignKeys */
        $foreignKeys = $connection->getSchemaBuilder()->getForeignKeys($table);

        return $this->foreignKeysCache[$table] = $foreignKeys;
    }

    /**
     * @return array<string, string>
     */
    private function inboundReferencedColumns(Connection $connection, string $table): array
    {
        $this->ensureInboundIndex($connection);

        return $this->inboundIndex[$this->canonicalTableName($connection, $table)] ?? [];
    }

    /**
     * Builds the connection-wide inbound-reference index at most once per
     * instance: a single getTableListing() sweep, and a single
     * getForeignKeys() call per listed table (shared with the outbound
     * cache via rawForeignKeys()), rather than repeating both per checked
     * table.
     */
    private function ensureInboundIndex(Connection $connection): void
    {
        if ($this->inboundIndexBuilt) {
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
                    $this->inboundIndex[$targetShort][$column] = $physicalShort;
                }
            }
        }

        $this->inboundIndexBuilt = true;
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
