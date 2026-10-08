<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnpageableTableException;

/**
 * Resolves the boot-time paging identity for a sanitize target, per the
 * four-source precedence in docs/design/engine-hardening/spec §R7 Paging
 * identity: schema primary key, a NOT NULL unique index disjoint from
 * fields(), a developer-declared Sanitizer::pagingKey(), and finally a NOT
 * NULL fallback column set of the string, integer, boolean and datetime
 * families — every source but the schema primary key additionally proven
 * unique with a one-shot boot-time probe. These proofs (unique, NOT NULL,
 * disjoint from fields()) are exactly what the design's stability proof
 * rests on: an identity value can never be
 * rewritten by this run's own writes, so keyset paging on it visits every
 * row exactly once regardless of in-place mutation elsewhere in the row.
 *
 * Sources 1 and model-key are disjoint from fields() too
 * (docs/design/referenced-identifier-structured-column-sanitization/spec,
 * "Primary-key and paging-identity columns"): SchemaGuard rejects a primary-key
 * column in fields() unless it is opted in through Sanitizer::mirrors(), and an
 * opted-in primary key is then paged on another identity or fails at boot.
 */
final class PagingKeyResolver
{
    /** The column families the fallback identity (source 4) may use. */
    private const FALLBACK_FAMILIES = ['string', 'integer', 'boolean', 'datetime'];

    public function __construct(
        private readonly UniqueColumnInspector $unique,
        private readonly ColumnConstraintInspector $columns,
    ) {}

    public function resolve(Model $target, Sanitizer $sanitizer): PagingKey
    {
        $table = $target->getTable();
        $conn = $target->getConnectionName();
        $fields = array_keys($sanitizer->fields());
        $schema = $this->columns->constraintsFor($table, $conn);

        // Source 1: schema primary key (composite-aware), or the model's
        // own declared key name when it is present in the table's columns.
        $pk = $this->unique->primaryKey($table, $conn);
        $driver = $target->getConnection()->getDriverName();

        // The first column refused for its type in sources 1 to 3, named in
        // the boot error when no source resolves.
        /** @var array{column: string, reason: string}|null $refused */
        $refused = null;

        if ($pk !== null && $pk !== [] && array_intersect($pk, $fields) === [] && $this->hasIdentityTypes($pk, $schema, $driver, $refused)) {
            return new PagingKey($pk, 'primary');
        }

        $keyName = $target->getKeyName();

        // Model::getKeyName()'s @return string PHPDoc does not reflect
        // reality for a TableRow target (its $primaryKey is intentionally
        // null — design §R7 Model-less table target), so it can genuinely
        // be null at runtime despite the declared type. The model key is only
        // a name the model declares, not a schema constraint, so it gets the
        // same NOT NULL check and uniqueness probe as a declared key;
        // otherwise resolution falls through.
        // @phpstan-ignore function.alreadyNarrowedType (getKeyName() can be null for a TableRow with $primaryKey = null, despite its string PHPDoc)
        if (is_string($keyName) && $keyName !== '' && $this->isEligible([$keyName], $schema, $fields) && $this->hasIdentityTypes([$keyName], $schema, $driver, $refused) && $this->isProvenUnique($target, [$keyName])) {
            return new PagingKey([$keyName], 'model-key');
        }

        // Source 2: the first NOT NULL unique index disjoint from fields().
        // getIndexes() does not say whether an index is partial (WHERE ...)
        // or holds an expression part, and either one makes the listed
        // columns unique only for some rows, or not at all. So the index is
        // only a candidate, and the uniqueness probe decides.
        foreach ($this->unique->uniqueConstraints($table, $conn) as $tuple) {
            if ($tuple !== [] && $this->isEligible($tuple, $schema, $fields) && $this->hasIdentityTypes($tuple, $schema, $driver, $refused) && $this->isProvenUnique($target, $tuple)) {
                return new PagingKey($tuple, 'unique');
            }
        }

        // Source 3: a developer-declared paging key, verified unique/NOT
        // NULL/disjoint from fields() before being trusted.
        $declared = $sanitizer->pagingKey();

        if ($declared !== [] && $this->isEligible($declared, $schema, $fields) && $this->hasIdentityTypes($declared, $schema, $driver, $refused) && $this->isProvenUnique($target, $declared)) {
            return new PagingKey($declared, 'declared');
        }

        // Source 4: every NOT NULL column not in fields() of a family whose
        // equality and ordering are reliable on every driver. json, binary,
        // decimal and a MySQL or MariaDB enum are left out, and so is every
        // type the package does not know (family 'other', such as a
        // PostgreSQL point or xml).
        $fallback = [];

        foreach ($schema as $column => $constraints) {
            if ($constraints->nullable || in_array($column, $fields, true)) {
                continue;
            }

            if (! in_array($constraints->family, self::FALLBACK_FAMILIES, true)) {
                $reason = $this->identityTypeRefusal($constraints, $driver);

                if ($reason !== null) {
                    $refused ??= ['column' => $column, 'reason' => $reason];
                }

                continue;
            }

            $fallback[] = $column;
        }

        if ($fallback !== [] && $this->isProvenUnique($target, $fallback)) {
            return new PagingKey($fallback, 'fallback');
        }

        $pkColumns = $pk ?? [];

        // @phpstan-ignore function.alreadyNarrowedType (getKeyName() can be null for a TableRow with $primaryKey = null, despite its string PHPDoc)
        if (is_string($keyName) && $keyName !== '' && isset($schema[$keyName]) && ! in_array($keyName, $pkColumns, true)) {
            $pkColumns[] = $keyName;
        }

        $optedIn = array_values(array_intersect($pkColumns, $fields));

        if ($optedIn !== []) {
            throw UnpageableTableException::optedInPrimaryKey($target::class, $table, $sanitizer::class, $optedIn[0]);
        }

        if ($refused !== null) {
            throw UnpageableTableException::unsupportedIdentityType($target::class, $table, $sanitizer::class, $refused['column'], $refused['reason']);
        }

        throw UnpageableTableException::noStableIdentity($target::class, $table, $sanitizer::class);
    }

    /**
     * Every column is in the table, NOT NULL, and not one of fields().
     *
     * @param  list<string>  $columns
     * @param  array<string, ColumnConstraints>  $schema
     * @param  list<string>  $fields
     */
    private function isEligible(array $columns, array $schema, array $fields): bool
    {
        foreach ($columns as $column) {
            if (! isset($schema[$column]) || $schema[$column]->nullable || in_array($column, $fields, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every column is of a type that can be paged on: its value, read back,
     * binds back to the same row, and the database orders and compares it
     * the same way. Records the first refused column in $refused.
     *
     * @param  list<string>  $columns
     * @param  array<string, ColumnConstraints>  $schema
     * @param  array{column: string, reason: string}|null  $refused
     */
    private function hasIdentityTypes(array $columns, array $schema, string $driver, ?array &$refused): bool
    {
        foreach ($columns as $column) {
            $reason = isset($schema[$column]) ? $this->identityTypeRefusal($schema[$column], $driver) : null;

            if ($reason !== null) {
                $refused ??= ['column' => $column, 'reason' => $reason];

                return false;
            }
        }

        return true;
    }

    /**
     * Why a column cannot be part of a paging identity on this driver, or
     * null when it can:
     *
     * - binary on PostgreSQL and SQLite: PDO returns a bytea value as a
     *   stream, and SQLite binds a blob back as text, which never equals a
     *   blob, so the UPDATE matches no row and the next page repeats.
     * - ENUM on MySQL and MariaDB: ORDER BY sorts it by member position,
     *   while the key-set filter compares it as text, so pages skip rows.
     * - single-precision FLOAT on MySQL and MariaDB: it is read back as a
     *   rounded double that never equals the stored 4-byte value.
     */
    private function identityTypeRefusal(ColumnConstraints $constraints, string $driver): ?string
    {
        if ($constraints->family === 'binary' && in_array($driver, ['pgsql', 'sqlite'], true)) {
            return 'is binary, and its value cannot be bound back to find the same row on this database.';
        }

        if ($constraints->family === 'enum') {
            return 'is an ENUM, which MySQL and MariaDB sort by member position but compare as text, so key-set pages would skip and repeat rows.';
        }

        if (in_array($driver, ['mysql', 'mariadb'], true) && preg_match('/^float\b/i', (string) $constraints->nativeType) === 1) {
            return 'is a single-precision FLOAT, which MySQL and MariaDB return as a rounded value that never equals the stored one.';
        }

        return null;
    }

    /**
     * One-shot boot-time probe proving a candidate column set is in fact
     * unique per row, since schema alone does not prove it for the model
     * key, a unique index (which can be partial or hold an expression), a
     * declared key or the fallback. A probe the database cannot run (no
     * equality operator for a column's type, for example) proves nothing,
     * so it counts as not unique. Inside an open transaction the probe runs
     * under a savepoint, so a failed probe does not abort the transaction
     * on PostgreSQL.
     *
     * @param  list<string>  $columns
     */
    private function isProvenUnique(Model $target, array $columns): bool
    {
        $connection = $target->getConnection();

        $probe = fn (): bool => $connection->table($target->getTable())
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->first() === null;

        try {
            return $connection->transactionLevel() > 0 ? $connection->transaction($probe) : $probe();
        } catch (QueryException) {
            return false;
        }
    }
}
