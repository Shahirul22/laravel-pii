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

    /**
     * Per namespace, whether each member is compared as a number (see
     * comparesAsNumber()), memoized until reset().
     *
     * @var array<string, list<bool>>
     */
    private array $numericMembers = [];

    /**
     * Without a column inspector every member is compared as a number when
     * it holds one, which on a text column only costs a retry for two
     * numeric strings that differ in leading or trailing zeros.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ?ColumnConstraintInspector $columns = null,
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

            $this->taken[$namespace][$this->tupleKey($values, $this->numericMembers($namespace, $table, $columns, $connection))] = true;
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<mixed>  $values
     */
    public function isTaken(string $table, array $columns, array $values, ?string $connection = null): bool
    {
        $namespace = $this->namespaceKey($table, $columns, $connection);

        return isset($this->taken[$namespace][$this->tupleKey($values, $this->numericMembers($namespace, $table, $columns, $connection))]);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<mixed>  $values
     */
    public function claim(string $table, array $columns, array $values, ?string $connection = null): void
    {
        $namespace = $this->namespaceKey($table, $columns, $connection);

        $this->taken[$namespace][$this->tupleKey($values, $this->numericMembers($namespace, $table, $columns, $connection))] = true;
    }

    public function reset(): void
    {
        $this->taken = [];
        $this->seeded = [];
        $this->numericMembers = [];
    }

    /**
     * Whether each member of the constraint is compared as a number: true
     * for an integer, decimal or boolean column, and for a column the
     * inspector does not know; false for a text, date or any other column,
     * where '007' and '7' are two values.
     *
     * @param  list<string>  $columns
     * @return list<bool>
     */
    private function numericMembers(string $namespace, string $table, array $columns, ?string $connection): array
    {
        if (isset($this->numericMembers[$namespace])) {
            return $this->numericMembers[$namespace];
        }

        $map = $this->columns?->constraintsFor($table, $connection) ?? [];

        return $this->numericMembers[$namespace] = array_map(
            fn (string $column): bool => ! isset($map[$column]) || in_array($map[$column]->family, ['integer', 'decimal', 'boolean'], true),
            $columns
        );
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
     * and null stays distinct from every other value.
     *
     * A string member is lowercased first (mb_strtolower, the same rule as
     * KeyedValueRegistry::comparisonKey()), because a case-insensitive
     * collation such as MySQL's utf8mb4_unicode_ci treats 'KAREN' and
     * 'karen' as the same unique value. On a case-sensitive collation this
     * only costs a retry for a value that differs from another in case alone.
     * Collations that also fold accents or trailing spaces are not covered.
     *
     * A member is keyed the way the database compares it, not by its PHP
     * type. On a numeric column (see numericMembers()) the integer 5, the
     * string '5' or '005' (numerify() returns a string) and the decimal
     * '5.00' read back from a decimal column are one value in a unique
     * index: an integer, a float, a boolean (written as 1 or 0) and a plain
     * decimal string ("-12", "0.50", ".5") become one canonical decimal text.
     * Such a key has no quotes, so it never equals the key of a non-numeric
     * string. On a text column a number is keyed as the text it is stored
     * as (5 -> '5', true -> '1'), and numeric strings are kept as written.
     *
     * A member json_encode() cannot encode (a string that is not valid
     * UTF-8) gets a binary-safe key instead: a NUL marker, which no JSON
     * encoding contains, then the bytes in hex. Without it every such
     * member would encode to the same empty key and falsely collide.
     *
     * @param  list<mixed>  $values
     * @param  list<bool>  $numeric  per member, whether it is compared as a number
     */
    private function tupleKey(array $values, array $numeric): string
    {
        return implode("\x1f", array_map(
            static function (mixed $value, bool $asNumber): string {
                if ($value instanceof \BackedEnum) {
                    $value = $value->value;
                }

                if ($asNumber) {
                    $number = self::canonicalNumber($value);

                    if ($number !== null) {
                        return $number;
                    }
                } elseif (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (is_int($value) || (is_float($value) && is_finite($value))) {
                    $value = (string) $value;
                }

                $encoded = json_encode(
                    is_string($value) && mb_check_encoding($value, 'UTF-8') ? mb_strtolower($value, 'UTF-8') : $value
                );

                if ($encoded !== false) {
                    return $encoded;
                }

                return "\x00".(is_string($value) ? 'bin:'.bin2hex($value) : 'php:'.bin2hex(serialize($value)));
            },
            $values,
            $numeric
        ));
    }

    /**
     * The canonical decimal text of a number: no sign on zero, no leading
     * zeros in the integer part, no trailing zeros in the fraction, and no
     * fraction when it is zero ("05" and "5.00" -> "5", ".50" -> "0.5",
     * "-0.0" -> "0"). Null for anything that is not an integer, a finite
     * float, a boolean or a plain decimal string. Strings are handled as
     * text, so an integer beyond PHP_INT_MAX keeps every digit.
     */
    private static function canonicalNumber(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            // The shortest text that reads back as the same float, as PHP prints it.
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/D', $value, $parts) !== 1) {
            return null;
        }

        $integer = ltrim($parts[2], '0');
        $fraction = rtrim($parts[3] ?? '', '0');

        if ($parts[2] === '' && ($parts[3] ?? '') === '') {
            return null;
        }

        $digits = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return $parts[1] === '-' && $digits !== '0' ? '-'.$digits : $digits;
    }
}
