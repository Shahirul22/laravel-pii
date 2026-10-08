<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Faker\Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Shahirul22\LaravelPiiSanitizer\Contracts\EnvironmentGuardContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;
use Shahirul22\LaravelPiiSanitizer\Exceptions\ConstraintViolationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UniquenessExhaustedException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsupportedCastException;
use Shahirul22\LaravelPiiSanitizer\Values\KeyedValueRegistry;

/**
 * Walks every configured model (and model-less table target) in
 * automatically-sized (or manually overridden) chunks, writing each chunk's
 * replacement values inside a single transaction, and never throws for a
 * mid-chunk failure — see docs/design/execution-engine-and-safety/execution-engine-spec,
 * docs/design/engine-hardening/spec §R7 and, for Keyed registration,
 * docs/design/value-generation-primitives/spec §R1.2, and for mirror groups
 * and the run-scoped foreign-key suspension around the targets loop
 * docs/design/referenced-identifier-structured-column-sanitization/spec.
 */
final class SanitizationRunner
{
    public function __construct(
        private readonly SanitizerResolverContract $resolver,
        private readonly ReplacementGenerator $generator,
        private readonly ChunkSizer $sizer,
        private readonly EnvironmentGuardContract $guard,
        private readonly Generator $faker,
        private readonly CastAwareEncoder $encoder,
        private readonly ColumnConstraintInspector $constraintInspector,
        private readonly ConstraintValidator $validator,
        private readonly PagingKeyResolver $pagingKeys,
        private readonly KeyedValueRegistry $keyed,
        private readonly ReferencedColumnGuard $referenced,
        private readonly ForeignKeySuspender $suspender,
    ) {}

    /** Walks every configured target; never throws for a mid-chunk failure. */
    public function run(RunOptions $options): RunReport
    {
        $this->guard->assertRunnable($options);

        $this->generator->reset();
        $this->keyed->reset();
        $this->sizer->reset();

        $rawModelClasses = $options->models ?? config('pii.models', []);

        if (! is_array($rawModelClasses)) {
            throw InvalidConfigurationException::modelsNotAList();
        }

        foreach ($rawModelClasses as $modelClass) {
            if (! is_string($modelClass) || $modelClass === '') {
                throw InvalidConfigurationException::modelsNotAList();
            }
        }

        /** @var list<class-string<Model>> $modelClasses */
        $modelClasses = $rawModelClasses;

        /** @var array<string, string> $tableMap table => sanitizer class-string, in declared order */
        $tableMap = [];

        // Table targets are excluded entirely when an explicit --model
        // filter is given (design §R7 Model-less table target: "a table has
        // no class to match against").
        if ($options->models === null) {
            $rawTables = config('pii.tables', []);

            if (! is_array($rawTables)) {
                throw InvalidConfigurationException::tablesNotAMap();
            }

            foreach ($rawTables as $tableName => $sanitizerClass) {
                if (! is_string($tableName) || $tableName === '' || ! is_string($sanitizerClass) || $sanitizerClass === '') {
                    throw InvalidConfigurationException::tablesNotAMap();
                }

                $tableMap[$tableName] = $sanitizerClass;
            }
        }

        // Registration pass (R2.2): resolve every configured target's
        // sanitizer up front, before any target's rows are read or written.
        // This surfaces an unsafe FK/FK-referenced column, or an unpageable
        // table, on target #3 before targets #1 or #2 have had a single row
        // touched, rather than only once the loop below happens to reach
        // that target. The resolved sanitizers are kept (not discarded) so
        // runModel() below reuses them instead of re-resolving — resolve()
        // re-instantiates the sanitizer and re-runs the full
        // SchemaGuard/DataQualityGuard check graph, which is wasted work to
        // repeat per target on every run.
        /** @var list<array{label: string, model: Model, sanitizer: ?Sanitizer}> $targets */
        $targets = [];

        foreach ($modelClasses as $modelClass) {
            $model = app($modelClass);

            if (! $model instanceof Model) {
                throw InvalidConfigurationException::invalidModelClass($modelClass);
            }

            $targets[] = [
                'label' => $modelClass,
                'model' => $model,
                'sanitizer' => $this->resolver->resolve($model),
            ];
        }

        foreach ($tableMap as $tableName => $sanitizerClass) {
            $row = TableRow::forTable($tableName);

            $targets[] = [
                'label' => "table:{$tableName}",
                'model' => $row,
                'sanitizer' => $this->resolver->resolveTable($tableName),
            ];
        }

        // Run-level mirror-group checks (R1.4, R1.5; spec "Boot-time checks"
        // 4 to 7): before Keyed originals seeding and paging probes, so an
        // invalid opt-in fails before any data query. Returns the connections
        // whose FK enforcement must be suspended for this run.
        $suspendConnections = $this->referenced->assertGroups($targets);

        // Classification runs once per target, here in the registration
        // pass, because it depends only on the target's model and column
        // names (design §R5) — never per row. The paging identity is also
        // resolved here (design §R7 Paging identity): a boot-time failure
        // (an unsafe column, an unpageable table) must surface before any
        // target's rows are read.
        /** @var array<string, list<string>> $castColumns */
        $castColumns = [];

        /** @var array<string, array<string, ColumnConstraints>> $columnConstraints */
        $columnConstraints = [];

        /** @var array<string, ?PagingKey> $pagingKeyByLabel */
        $pagingKeyByLabel = [];

        /** @var array<string, array<string, string>> $valueTypes */
        $valueTypes = [];

        foreach ($targets as $target) {
            $label = $target['label'];
            $model = $target['model'];
            $sanitizer = $target['sanitizer'];

            // Keyed values (value-generation-primitives spec, R1.1/R1.2): the
            // key check, shape-signature check and binding/originals
            // collection all happen here, before any row is read.
            if ($sanitizer !== null) {
                $this->keyed->register($model, $sanitizer);
            }

            $castColumns[$label] = $sanitizer === null
                ? []
                : $this->encoder->classify($model, array_keys($sanitizer->fields()));

            $columnConstraints[$label] = $sanitizer === null
                ? []
                : array_intersect_key(
                    $this->constraintInspector->constraintsFor($model->getTable(), $model->getConnectionName()),
                    array_flip(array_keys($sanitizer->fields()))
                );

            $pagingKeyByLabel[$label] = $sanitizer === null
                ? null
                : $this->pagingKeys->resolve($model, $sanitizer);

            // PostgreSQL only: the type each bound value is cast to in the
            // batched UPDATE (see BatchUpdateStatement). Empty elsewhere,
            // so every other driver's SQL is unchanged.
            $valueTypes[$label] = BatchUpdateStatement::valueTypesFor($model->getConnection()->getDriverName(), $columnConstraints[$label]);
        }

        $reports = [];
        $restores = [];
        $foreignKeysSuspended = false;
        $triggersSuspended = false;

        try {
            if (! $options->dryRun) {
                foreach ($suspendConnections as $connection) {
                    $restore = $this->suspender->suspend($connection);

                    if ($restore !== null) {
                        $restores[] = $restore;
                        $foreignKeysSuspended = true;
                        $triggersSuspended = $triggersSuspended || $this->suspender->suspendsTriggers($connection);
                    }
                }
            }

            foreach ($targets as $target) {
                $label = $target['label'];

                $modelReport = $this->runModel(
                    $label,
                    $target['model'],
                    $target['sanitizer'],
                    $castColumns[$label],
                    $columnConstraints[$label],
                    $pagingKeyByLabel[$label],
                    $valueTypes[$label],
                    $options
                );
                $reports[] = $modelReport;

                if ($modelReport->failed()) {
                    break;
                }
            }
        } finally {
            $this->restoreForeignKeys($restores);
        }

        return new RunReport($reports, $options->dryRun, $foreignKeysSuspended, $triggersSuspended);
    }

    /**
     * Restores every suspended connection in reverse order. Every restore is
     * attempted; the first failure is re-thrown afterwards, never swallowed
     * (PHP chains any in-flight exception as its previous).
     *
     * @param  list<\Closure(): void>  $restores
     */
    private function restoreForeignKeys(array $restores): void
    {
        $failure = null;

        foreach (array_reverse($restores) as $restore) {
            try {
                $restore();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * One target, one report; the unit run() loops over.
     *
     * Private, and the class is final: this is only reachable through the
     * guarded and reset entry point run() above, so the environment guard
     * and generator reset can never be bypassed by calling into the engine
     * directly or through a subclass. $model is not re-validated here —
     * run()'s registration pass above already built it (and, for a
     * pii.models entry, confirmed it is a Model instance).
     */
    /**
     * @param  list<string>  $castColumns
     * @param  array<string, ColumnConstraints>  $columnConstraints
     * @param  array<string, string>  $valueTypes
     */
    private function runModel(string $label, Model $model, ?Sanitizer $sanitizer, array $castColumns, array $columnConstraints, ?PagingKey $pagingKey, array $valueTypes, RunOptions $options): ModelReport
    {
        $table = $model->getTable();

        if ($sanitizer === null) {
            return new ModelReport(
                modelClass: $label,
                table: $table,
                chunkSize: 0,
                automaticChunkSize: false,
                rowsScanned: 0,
                expectedChunks: 0,
                chunks: [],
                skipReason: 'no-sanitizer',
            );
        }

        if ($pagingKey === null) {
            throw new \LogicException("[laravel-pii-sanitizer] Internal error: {$label} has a sanitizer but no resolved paging identity.");
        }

        $size = $this->sizer->sizeFor($model, $options);
        $automatic = $this->sizer->wasAutomatic();
        $expectedChunks = (int) ceil($this->sizer->countFor($model) / $size);

        $options->onProgress?->__invoke(new ProgressEvent(
            modelClass: $label,
            table: $table,
            expectedChunks: $expectedChunks,
        ));

        $chunks = [];
        $rowsScanned = 0;
        /** @var array<string, int> $columnCounts */
        $columnCounts = [];
        $index = 0;

        $onChunk = function (Collection $rows) use (
            &$chunks, &$rowsScanned, &$columnCounts, &$index, $sanitizer, $castColumns, $columnConstraints, $valueTypes, $options, $label, $table, $expectedChunks, $pagingKey
        ): ?bool {
            $index++;

            $chunk = $this->processChunk($sanitizer, $rows->all(), $castColumns, $columnConstraints, $valueTypes, $pagingKey->columns, $index, $options, $columnCounts);

            $chunks[] = $chunk;
            $rowsScanned += $rows->count();

            $options->onProgress?->__invoke(new ProgressEvent(
                modelClass: $label,
                table: $table,
                expectedChunks: $expectedChunks,
                chunk: $chunk,
                rowsProcessed: $rowsScanned,
            ));

            if ($chunk->status !== ChunkStatus::Completed) {
                return false;
            }

            return null;
        };

        // Every target is read without its global scopes (SoftDeletes
        // included): a row a scope hides is still a row holding PII, and the
        // raw-table paths (the batched UPDATE, Keyed originals, uniqueness
        // seeding, paging probes) already see it. ChunkSizer::countFor()
        // counts the same way.
        $query = $model->newQueryWithoutScopes();

        if ($pagingKey->isSingle()) {
            $column = $pagingKey->columns[0];

            // v1's exact call for the single-int-PK and UUID/string-PK
            // cases (design §Read and write mechanics): chunkById()'s
            // forPageAfterId() uses a > comparison that already works
            // identically on a lexicographically-ordered string key. But
            // chunkById() reads last_seen through data_get(), so a cast or
            // accessor on the identity column (the primary key included)
            // would hand it the transformed value, not the stored one; such
            // a column is paged by key set, which reads the raw attribute.
            if (! self::readsTransformed($model, $column)) {
                $query->chunkById($size, $onChunk, $column);
            } else {
                $this->chunkByKeyset($query, $pagingKey->columns, $size, $onChunk);
            }
        } else {
            $this->chunkByKeyset($query, $pagingKey->columns, $size, $onChunk);
        }

        return new ModelReport(
            modelClass: $label,
            table: $table,
            chunkSize: $size,
            automaticChunkSize: $automatic,
            rowsScanned: $rowsScanned,
            expectedChunks: $expectedChunks,
            chunks: $chunks,
            columnCounts: $columnCounts,
        );
    }

    /**
     * Keyset pagination on the resolved identity — never OFFSET/LIMIT. Each
     * page is `WHERE (identity) > (last_seen) ORDER BY identity LIMIT n`,
     * built by KeysetPredicate as a row-value comparison `(a, b) > (?, ?)`
     * on PostgreSQL and SQLite 3.15+, where that is an index range, and as
     * the expanded predicate `(a > ?) OR (a = ? AND b > ?) OR ...` on every
     * other driver. See docs/design/engine-hardening/spec §R7 Stability proof: the
     * identity is unique and NOT NULL by construction (PagingKeyResolver),
     * and this run never writes to it (identity ∩ fields() = ∅ is an
     * enforced boot invariant), so every row whose identity was greater than
     * last_seen at the start of page k is still greater than last_seen at
     * the start of page k+1 — each row is visited exactly once across the
     * whole run, never skipped, never repeated, regardless of how many rows
     * this run mutates along the way.
     *
     * @param  list<string>  $columns
     * @param  \Closure(Collection<int, Model>): (bool|null)  $callback
     */
    private function chunkByKeyset(Builder $query, array $columns, int $size, \Closure $callback): void
    {
        $base = clone $query;

        foreach ($columns as $column) {
            $base->orderBy($column);
        }

        $lastSeen = null;

        $connection = $query->getModel()->getConnection();
        $rowValues = count($columns) > 1
            && KeysetPredicate::supportsRowValues($connection->getDriverName(), (string) $connection->getServerVersion());

        while (true) {
            $page = clone $base;

            if ($lastSeen !== null) {
                KeysetPredicate::apply($page, $columns, $lastSeen, $rowValues);
            }

            $rows = $page->limit($size)->get();

            if ($rows->isEmpty()) {
                break;
            }

            /** @var Model $lastRow */
            $lastRow = $rows->last();
            $lastAttributes = $lastRow->getAttributes();

            $lastSeen = [];

            foreach ($columns as $column) {
                $lastSeen[$column] = $lastAttributes[$column];
            }

            $result = $callback($rows);

            if ($result === false) {
                break;
            }

            if ($rows->count() < $size) {
                break;
            }
        }
    }

    /**
     * @param  list<Model>  $rows
     * @param  list<string>  $castColumns
     * @param  array<string, ColumnConstraints>  $columnConstraints
     * @param  array<string, string>  $valueTypes
     * @param  list<string>  $identityColumns
     * @param  array<string, int>  $columnCounts
     */
    private function processChunk(Sanitizer $sanitizer, array $rows, array $castColumns, array $columnConstraints, array $valueTypes, array $identityColumns, int $index, RunOptions $options, array &$columnCounts): ChunkReport
    {
        $rowCount = count($rows);
        $firstKey = $rowCount > 0 ? $this->reportKey($this->identityOf($rows[0], $identityColumns)) : null;
        $lastKey = $rowCount > 0 ? $this->reportKey($this->identityOf($rows[$rowCount - 1], $identityColumns)) : null;

        if ($rowCount === 0) {
            return new ChunkReport(
                index: $index,
                rowCount: 0,
                status: ChunkStatus::Completed,
                firstKey: $firstKey,
                lastKey: $lastKey,
            );
        }

        $connection = $rows[0]->getConnection();
        $table = $rows[0]->getTable();

        /** @var array<string, int> $chunkColumnCounts */
        $chunkColumnCounts = [];

        $apply = function () use ($rows, $sanitizer, $castColumns, $columnConstraints, $valueTypes, $identityColumns, $connection, $table, $options, &$chunkColumnCounts): void {
            /** @var list<array{identity: array<string, mixed>, values: array<string, mixed>}> $rowsToWrite */
            $rowsToWrite = [];

            foreach ($rows as $row) {
                // Generate: the logical replacement value, per column.
                $values = $this->generator->forRow($sanitizer, $row, $this->faker);

                // The changed-count comparison stays on the logical
                // (pre-encode) value against the row's current cast-decoded
                // attribute — a reporting concern, not a storage concern.
                foreach ($values as $column => $value) {
                    if ($value !== $row->getAttribute($column)) {
                        $chunkColumnCounts[$column] = ($chunkColumnCounts[$column] ?? 0) + 1;
                    }
                }

                // Encode (R5): route a cast-bearing column's logical value
                // through the model's own cast/mutator pipeline so the
                // stored value is exactly what Eloquent itself would write.
                // A plain column is left untouched, so it still passes
                // through bindableValue() (Normalize) exactly as in v1.
                foreach ($castColumns as $column) {
                    if (array_key_exists($column, $values)) {
                        $values[$column] = $this->encoder->encode($row, $column, $values[$column]);
                    }
                }

                // Validate (R6): check the normalized, post-encode value of
                // every declared column that has schema constraints, before
                // any SQL for this chunk is built. Every violation raises
                // ConstraintViolationException, rolling back the whole
                // chunk via the catch below — never a coerced/truncated
                // write.
                foreach ($values as $column => $value) {
                    if (isset($columnConstraints[$column])) {
                        $this->validator->assertWritable($row::class, $table, $columnConstraints[$column], self::bindableValue($value));
                    }
                }

                $rowsToWrite[] = [
                    'identity' => $this->identityOf($row, $identityColumns),
                    'values' => $values,
                ];
            }

            if (! $options->dryRun) {
                $this->batchUpdate($connection, $table, $identityColumns, $rowsToWrite, $valueTypes);
            }
        };

        try {
            if ($options->dryRun) {
                // No transaction is opened at all for a dry run (design §5.2) — a
                // begin/rollback would still acquire locks and write to the WAL/undo
                // log, so skipping the transaction entirely is the stronger guarantee.
                $apply();
            } else {
                $connection->transaction($apply, 1);
            }

            // Only merge this chunk's column-count deltas into the caller's
            // running total once the write (or dry-run apply) has actually
            // succeeded — if the transaction rolls back below, none of this
            // chunk's counts should be reflected in the report.
            foreach ($chunkColumnCounts as $column => $count) {
                $columnCounts[$column] = ($columnCounts[$column] ?? 0) + $count;
            }
        } catch (UniquenessExhaustedException|InvalidCategoricalColumnException|InvalidConfigurationException|InvalidReplacementValueException|UnsupportedCastException|ConstraintViolationException $e) {
            // These are the package's own named exceptions: their messages
            // are built only from column/model/table names, never row data,
            // so — unlike a raw driver exception — they are safe to surface
            // verbatim and far more actionable than the redacted message
            // below.
            return new ChunkReport(
                index: $index,
                rowCount: $rowCount,
                status: ChunkStatus::RolledBack,
                firstKey: $firstKey,
                lastKey: $lastKey,
                failureMessage: $e->getMessage(),
                failureClass: $e::class,
            );
        } catch (\Throwable $e) {
            return new ChunkReport(
                index: $index,
                rowCount: $rowCount,
                status: ChunkStatus::RolledBack,
                firstKey: $firstKey,
                lastKey: $lastKey,
                // Store a redacted, generic failure reason rather than the raw
                // exception message: DB driver exceptions can embed bound
                // values or row fragments (e.g. duplicate-key messages), which
                // could leak real PII into a report/log surfaced by the
                // artisan command.
                failureMessage: sprintf('%s: database exception during chunk write — see application logs for detail.', $e::class),
                failureClass: $e::class,
            );
        }

        return new ChunkReport(
            index: $index,
            rowCount: $rowCount,
            status: ChunkStatus::Completed,
            firstKey: $firstKey,
            lastKey: $lastKey,
        );
    }

    /**
     * A row's identity value per column, keyed by column name, always read
     * from the raw, un-cast attribute — the primary key included. It must
     * match the identity as it exists on disk, not as a cast or accessor
     * transforms it (design §Read and write mechanics): getKey() applies
     * the key's cast, so a batched UPDATE bound to it would match no row.
     *
     * @param  list<string>  $identityColumns
     * @return array<string, mixed>
     */
    private function identityOf(Model $row, array $identityColumns): array
    {
        $attributes = $row->getAttributes();
        $identity = [];

        foreach ($identityColumns as $column) {
            $identity[$column] = $attributes[$column];
        }

        return $identity;
    }

    /**
     * Whether getAttribute() on this column returns something other than the
     * stored value: a get mutator, an Attribute accessor, or a cast. The one
     * cast that does not count is the implicit key-type cast Eloquent adds
     * for an incrementing key (getCasts() merges [keyName => keyType]), which
     * every default model carries and which leaves an integer key unchanged.
     */
    private static function readsTransformed(Model $model, string $column): bool
    {
        if ($model->hasGetMutator($column) || $model->hasAttributeGetMutator($column)) {
            return true;
        }

        if (! $model->hasCast($column)) {
            return false;
        }

        $implicitKeyCast = $column === $model->getKeyName()
            && $model->getIncrementing()
            && $model->getCasts()[$column] === $model->getKeyType();

        return ! $implicitKeyCast;
    }

    /**
     * The bare scalar for a single-column identity (v1's unchanged shape),
     * or the associative column => value array for a multi-column one — see
     * docs/design/engine-hardening/spec §R7 Reporting.
     *
     * @param  array<string, mixed>  $identity
     */
    private function reportKey(array $identity): mixed
    {
        return count($identity) === 1 ? reset($identity) : $identity;
    }

    /**
     * A conservative ceiling on bound parameters per batched UPDATE
     * statement, well under SQLite's historical SQLITE_MAX_VARIABLE_NUMBER
     * default of 999 (older builds) — a wide sanitizer (many declared
     * columns) times a large configured chunk size can otherwise produce
     * tens of thousands of placeholders in one statement (2 per row per
     * column for the CASE/WHEN pairs, plus 1 per row for the WHERE...IN),
     * which some drivers reject outright.
     */
    private const MAX_BOUND_PARAMETERS_PER_STATEMENT = 400;

    /**
     * Writes an entire chunk's replacement values in one or more UPDATE
     * statements instead of one UPDATE per row, using a CASE/WHEN per column
     * keyed on the identity — rows in a chunk generally each get distinct
     * values (Faker, closures, uniqueness retries), so a single shared-value
     * UPDATE isn't possible, but the per-row round trip is still avoidable.
     * Built as parameterized raw statements (every value passed as a bound
     * "?", never interpolated) since the query builder's update() only
     * binds plain column => scalar pairs, not per-row CASE expressions.
     * Sub-batched by MAX_BOUND_PARAMETERS_PER_STATEMENT so a wide sanitizer
     * times a large chunk size never produces a single statement with more
     * placeholders than a driver allows.
     *
     * @param  list<string>  $identityColumns
     * @param  list<array{identity: array<string, mixed>, values: array<string, mixed>}>  $rowsToWrite
     * @param  array<string, string>  $valueTypes  column => cast type, PostgreSQL only (BatchUpdateStatement::valueTypesFor())
     */
    private function batchUpdate(Connection $connection, string $table, array $identityColumns, array $rowsToWrite, array $valueTypes): void
    {
        $columns = array_keys($rowsToWrite[0]['values']);
        $columnCount = count($columns);
        $k = count($identityColumns);

        // Per row this statement binds (k + 1) placeholders per column
        // (CASE/WHEN pairs: k identity-equality bindings plus 1 value)
        // plus k for the row selector. For k = 1 this is v1's (n*2)+1.
        $parametersPerRow = $columnCount * ($k + 1) + $k;
        $rowsPerSubBatch = max(1, intdiv(self::MAX_BOUND_PARAMETERS_PER_STATEMENT, $parametersPerRow));

        foreach (array_chunk($rowsToWrite, $rowsPerSubBatch) as $subBatch) {
            [$sql, $bindings] = BatchUpdateStatement::build($connection->getQueryGrammar(), $table, $identityColumns, $columns, $subBatch, $valueTypes);

            $connection->update($sql, $bindings);
        }
    }

    /**
     * Normalizes a value accepted by ReplacementGenerator::isWritableValue()
     * into something safe to hand directly to
     * Connection::update()/PDOStatement::bindValue(). This call bypasses
     * Illuminate\Database\Query\Builder::update() entirely (needed for the
     * per-row CASE/WHEN construction above), so it does not benefit from
     * that method's own value normalization — an array bound as-is silently
     * PHP-casts to the literal string "Array" via PDO with no exception,
     * silently corrupting the column instead of erroring.
     *
     * Public and static (not merely private) so SchemaGuard's boot-time
     * validation (§R6 Boot vs per-row split) can normalize a static value
     * the exact same way before validating it — a single source of truth
     * for "Normalize" shared by both the boot and per-row validation paths.
     */
    public static function bindableValue(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => json_encode($value),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            default => $value,
        };
    }
}
