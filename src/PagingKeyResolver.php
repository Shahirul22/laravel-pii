<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnpageableTableException;

/**
 * Resolves the boot-time paging identity for a sanitize target, per the
 * four-source precedence in docs/design/engine-hardening/spec §R7 Paging
 * identity: schema primary key, a NOT NULL unique index disjoint from
 * fields(), a developer-declared Sanitizer::pagingKey(), and finally a NOT
 * NULL / non-json-binary-decimal fallback column set — each data-dependent
 * source additionally proven unique with a one-shot boot-time probe. These
 * proofs (unique, NOT NULL, disjoint from fields()) are exactly what the
 * design's stability proof rests on: an identity value can never be
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

        if ($pk !== null && $pk !== [] && array_intersect($pk, $fields) === []) {
            return new PagingKey($pk, 'primary');
        }

        $keyName = $target->getKeyName();

        // Model::getKeyName()'s @return string PHPDoc does not reflect
        // reality for a TableRow target (its $primaryKey is intentionally
        // null — design §R7 Model-less table target), so it can genuinely
        // be null at runtime despite the declared type.
        // @phpstan-ignore function.alreadyNarrowedType (getKeyName() can be null for a TableRow with $primaryKey = null, despite its string PHPDoc)
        if (is_string($keyName) && $keyName !== '' && isset($schema[$keyName]) && ! in_array($keyName, $fields, true)) {
            return new PagingKey([$keyName], 'model-key');
        }

        // Source 2: the first NOT NULL unique index disjoint from fields().
        foreach ($this->unique->uniqueConstraints($table, $conn) as $tuple) {
            $eligible = true;

            foreach ($tuple as $column) {
                if (! isset($schema[$column]) || $schema[$column]->nullable || in_array($column, $fields, true)) {
                    $eligible = false;
                    break;
                }
            }

            if ($eligible) {
                return new PagingKey($tuple, 'unique');
            }
        }

        // Source 3: a developer-declared paging key, verified unique/NOT
        // NULL/disjoint from fields() before being trusted.
        $declared = $sanitizer->pagingKey();

        if ($declared !== []) {
            $eligible = true;

            foreach ($declared as $column) {
                if (! isset($schema[$column]) || $schema[$column]->nullable || in_array($column, $fields, true)) {
                    $eligible = false;
                    break;
                }
            }

            if ($eligible && $this->isProvenUnique($target, $declared)) {
                return new PagingKey($declared, 'declared');
            }
        }

        // Source 4: every NOT NULL column not in fields() and not of a
        // family whose equality/ordering is unreliable across drivers.
        $fallback = [];

        foreach ($schema as $column => $constraints) {
            if ($constraints->nullable || in_array($column, $fields, true)) {
                continue;
            }

            if (in_array($constraints->family, ['json', 'binary', 'decimal'], true)) {
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

        throw UnpageableTableException::noStableIdentity($target::class, $table, $sanitizer::class);
    }

    /**
     * One-shot boot-time probe proving a data-dependent candidate column set
     * is in fact unique per row, since schema alone does not prove it for
     * sources 3 and 4.
     *
     * @param  list<string>  $columns
     */
    private function isProvenUnique(Model $target, array $columns): bool
    {
        return $target->getConnection()->table($target->getTable())
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->first() === null;
    }
}
