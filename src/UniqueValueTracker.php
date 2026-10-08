<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\DatabaseManager;

/**
 * A run-scoped, package-owned uniqueness tracker: knows both values already
 * present in a column (seeded lazily from the schema) and values claimed
 * during the current run, so a replacement never collides with either
 * (R4.1, AC-5). Bound as a singleton — its state spans exactly one
 * `pii:sanitize` invocation and is cleared via reset().
 */
class UniqueValueTracker
{
    /** @var array<string, array<string, true>> */
    private array $taken = [];

    /** @var array<string, true> */
    private array $seeded = [];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Load every value tuple already present in the table for this
     * constraint into the in-memory taken-set. Idempotent: runs at most
     * once per (table, tuple) per run.
     *
     * @param  list<string>  $columns
     */
    public function seed(string $table, array $columns, ?string $connection = null): void
    {
        $namespace = $this->namespaceKey($table, $columns, $connection);

        if (isset($this->seeded[$namespace])) {
            return;
        }

        // Mark seeded first so a re-entrant call cannot re-query.
        $this->seeded[$namespace] = true;

        // cursor() streams rows one at a time from the DB driver rather than
        // materializing the full distinct set in memory up front — the whole
        // point of chunking elsewhere in the engine (R5.2) would otherwise be
        // defeated by this single unbounded ->get().
        foreach ($this->db->connection($connection)->table($table)->select($columns)->distinct()->cursor() as $row) {
            $attributes = (array) $row;

            $values = array_map(
                fn (string $column): mixed => $attributes[$column] ?? null,
                $columns
            );

            $this->taken[$namespace][$this->tupleKey($values)] = true;
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<mixed>  $values
     */
    public function isTaken(string $table, array $columns, array $values, ?string $connection = null): bool
    {
        $namespace = $this->namespaceKey($table, $columns, $connection);

        return isset($this->taken[$namespace][$this->tupleKey($values)]);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<mixed>  $values
     */
    public function claim(string $table, array $columns, array $values, ?string $connection = null): void
    {
        $namespace = $this->namespaceKey($table, $columns, $connection);

        $this->taken[$namespace][$this->tupleKey($values)] = true;
    }

    public function reset(): void
    {
        $this->taken = [];
        $this->seeded = [];
    }

    /**
     * @param  list<string>  $columns
     */
    private function namespaceKey(string $table, array $columns, ?string $connection = null): string
    {
        return ($connection ?? '')."\x1e".$table."\x1e".implode("\x1f", $columns);
    }

    /**
     * JSON-encodes each tuple member before joining with a control-character
     * separator, so members containing the separator itself never collide,
     * and null/'1'/1/true remain distinguishable.
     *
     * A string member is lowercased first (mb_strtolower, the same rule as
     * KeyedValueRegistry::comparisonKey()), because a case-insensitive
     * collation such as MySQL's utf8mb4_unicode_ci treats 'KAREN' and
     * 'karen' as the same unique value. On a case-sensitive collation this
     * only costs a retry for a value that differs from another in case alone.
     * Collations that also fold accents or trailing spaces are not covered.
     *
     * A member json_encode() cannot encode (a string that is not valid
     * UTF-8) gets a binary-safe key instead: a NUL marker, which no JSON
     * encoding contains, then the bytes in hex. Without it every such
     * member would encode to the same empty key and falsely collide.
     *
     * @param  list<mixed>  $values
     */
    private function tupleKey(array $values): string
    {
        return implode("\x1f", array_map(
            static function (mixed $value): string {
                $encoded = json_encode(
                    is_string($value) && mb_check_encoding($value, 'UTF-8') ? mb_strtolower($value, 'UTF-8') : $value
                );

                if ($encoded !== false) {
                    return $encoded;
                }

                return "\x00".(is_string($value) ? 'bin:'.bin2hex($value) : 'php:'.bin2hex(serialize($value)));
            },
            $values
        ));
    }
}
