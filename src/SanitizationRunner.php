<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Faker\Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\EnvironmentGuardContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UniquenessExhaustedException;

/**
 * Walks every configured model in automatically-sized (or manually
 * overridden) chunks, writing each chunk's replacement values inside a
 * single transaction, and never throws for a mid-chunk failure — see
 * docs/design/execution-engine-and-safety/execution-engine-spec.
 */
final class SanitizationRunner
{
    public function __construct(
        private readonly SanitizerResolverContract $resolver,
        private readonly ReplacementGenerator $generator,
        private readonly ChunkSizer $sizer,
        private readonly EnvironmentGuardContract $guard,
        private readonly Generator $faker,
    ) {}

    /** Walks every configured model; never throws for a mid-chunk failure. */
    public function run(RunOptions $options): RunReport
    {
        $this->guard->assertRunnable($options);

        $this->generator->reset();
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

        // Registration pass (R2.2): resolve every configured model's
        // sanitizer up front, before any model's rows are read or written.
        // This surfaces an unsafe FK/FK-referenced column on model #3 before
        // model #1 or #2 have had a single row touched, rather than only
        // once the loop below happens to reach that model. The resolved
        // sanitizers are kept (not discarded) so runModel() below reuses
        // them instead of re-resolving — resolve() re-instantiates the
        // sanitizer and re-runs the full SchemaGuard/DataQualityGuard check
        // graph, which is wasted work to repeat per model on every run.
        /** @var array<class-string<Model>, ?Sanitizer> $sanitizers */
        $sanitizers = [];

        foreach ($modelClasses as $modelClass) {
            $model = app($modelClass);

            if (! $model instanceof Model) {
                throw InvalidConfigurationException::invalidModelClass($modelClass);
            }

            $sanitizers[$modelClass] = $this->resolver->resolve($model);
        }

        $reports = [];

        foreach ($modelClasses as $modelClass) {
            $modelReport = $this->runModel($modelClass, $sanitizers[$modelClass], $options);
            $reports[] = $modelReport;

            if ($modelReport->failed()) {
                break;
            }
        }

        return new RunReport($reports, $options->dryRun);
    }

    /**
     * One model, one report; the unit run() loops over.
     *
     * Private, and the class is final: this is only reachable through the
     * guarded and reset entry point run() above, so the environment guard
     * and generator reset can never be bypassed by calling into the engine
     * directly or through a subclass. $modelClass is not re-validated here —
     * run()'s registration pass above already confirmed app($modelClass)
     * instanceof Model for every entry in the same $modelClasses list this
     * is called from, so a repeated check here could never fail.
     */
    private function runModel(string $modelClass, ?Sanitizer $sanitizer, RunOptions $options): ModelReport
    {
        /** @var Model $model */
        $model = app($modelClass);

        $table = $model->getTable();

        if ($sanitizer === null) {
            return new ModelReport(
                modelClass: $modelClass,
                table: $table,
                chunkSize: 0,
                automaticChunkSize: false,
                rowsScanned: 0,
                expectedChunks: 0,
                chunks: [],
                skipReason: 'no-sanitizer',
            );
        }

        $key = $model->getKeyName();

        $size = $this->sizer->sizeFor($model, $options);
        $automatic = $this->sizer->wasAutomatic();
        $expectedChunks = (int) ceil($this->sizer->countFor($model) / $size);

        $options->onProgress?->__invoke(new ProgressEvent(
            modelClass: $modelClass,
            table: $table,
            expectedChunks: $expectedChunks,
        ));

        $chunks = [];
        $rowsScanned = 0;
        /** @var array<string, int> $columnCounts */
        $columnCounts = [];
        $index = 0;

        $model->newQuery()->chunkById($size, function ($rows) use (
            &$chunks, &$rowsScanned, &$columnCounts, &$index, $sanitizer, $options, $modelClass, $table, $expectedChunks
        ) {
            $index++;

            $chunk = $this->processChunk($sanitizer, $rows->all(), $index, $options, $columnCounts);

            $chunks[] = $chunk;
            $rowsScanned += $rows->count();

            $options->onProgress?->__invoke(new ProgressEvent(
                modelClass: $modelClass,
                table: $table,
                expectedChunks: $expectedChunks,
                chunk: $chunk,
                rowsProcessed: $rowsScanned,
            ));

            if ($chunk->status !== ChunkStatus::Completed) {
                return false;
            }
        }, $key);

        return new ModelReport(
            modelClass: $modelClass,
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
     * @param  list<Model>  $rows
     * @param  array<string, int>  $columnCounts
     */
    private function processChunk(Sanitizer $sanitizer, array $rows, int $index, RunOptions $options, array &$columnCounts): ChunkReport
    {
        $rowCount = count($rows);
        $firstKey = $rowCount > 0 ? $rows[0]->getKey() : null;
        $lastKey = $rowCount > 0 ? $rows[$rowCount - 1]->getKey() : null;

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
        $key = $rows[0]->getKeyName();

        /** @var array<string, int> $chunkColumnCounts */
        $chunkColumnCounts = [];

        $apply = function () use ($rows, $sanitizer, $connection, $table, $key, $options, &$chunkColumnCounts): void {
            /** @var array<string, array<int|string, mixed>> $valuesByRowKey */
            $valuesByRowKey = [];

            foreach ($rows as $row) {
                $values = $this->generator->forRow($sanitizer, $row, $this->faker);

                foreach ($values as $column => $value) {
                    if ($value !== $row->getAttribute($column)) {
                        $chunkColumnCounts[$column] = ($chunkColumnCounts[$column] ?? 0) + 1;
                    }
                }

                $valuesByRowKey[$row->getKey()] = $values;
            }

            if (! $options->dryRun) {
                $this->batchUpdate($connection, $table, $key, $valuesByRowKey);
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
        } catch (UniquenessExhaustedException|InvalidCategoricalColumnException|InvalidConfigurationException|InvalidReplacementValueException $e) {
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
     * keyed on the primary key — rows in a chunk generally each get distinct
     * values (Faker, closures, uniqueness retries), so a single shared-value
     * UPDATE isn't possible, but the per-row round trip is still avoidable.
     * Built as parameterized raw statements (every value passed as a bound
     * "?", never interpolated) since the query builder's update() only
     * binds plain column => scalar pairs, not per-row CASE expressions.
     * Sub-batched by MAX_BOUND_PARAMETERS_PER_STATEMENT so a wide sanitizer
     * times a large chunk size never produces a single statement with more
     * placeholders than a driver allows.
     *
     * @param  array<int|string, array<string, mixed>>  $valuesByRowKey  row key => column => value
     */
    private function batchUpdate(Connection $connection, string $table, string $key, array $valuesByRowKey): void
    {
        $columns = array_keys(reset($valuesByRowKey));
        $columnCount = count($columns);

        // Per row this statement binds 2 placeholders per column (CASE/WHEN
        // pairs) plus 1 for the WHERE...IN clause.
        $parametersPerRow = ($columnCount * 2) + 1;
        $rowsPerSubBatch = max(1, intdiv(self::MAX_BOUND_PARAMETERS_PER_STATEMENT, $parametersPerRow));

        foreach (array_chunk(array_keys($valuesByRowKey), $rowsPerSubBatch, true) as $subBatchKeys) {
            $subBatch = array_intersect_key($valuesByRowKey, array_flip($subBatchKeys));

            $this->batchUpdateStatement($connection, $table, $key, $columns, $subBatch);
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  array<int|string, array<string, mixed>>  $valuesByRowKey  row key => column => value
     */
    private function batchUpdateStatement(Connection $connection, string $table, string $key, array $columns, array $valuesByRowKey): void
    {
        $keys = array_keys($valuesByRowKey);
        $grammar = $connection->getQueryGrammar();

        $wrappedTable = $grammar->wrapTable($table);
        $wrappedKey = $grammar->wrap($key);

        $setClauses = [];
        $bindings = [];

        foreach ($columns as $column) {
            $whenClauses = [];

            foreach ($keys as $rowKey) {
                $whenClauses[] = 'WHEN ? THEN ?';
                $bindings[] = $rowKey;
                $bindings[] = $this->bindableValue($valuesByRowKey[$rowKey][$column]);
            }

            $setClauses[] = sprintf(
                '%s = CASE %s %s END',
                $grammar->wrap($column),
                $wrappedKey,
                implode(' ', $whenClauses)
            );
        }

        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s IN (%s)',
            $wrappedTable,
            implode(', ', $setClauses),
            $wrappedKey,
            $placeholders
        );

        $bindings = [...$bindings, ...$keys];

        $connection->update($sql, $bindings);
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
     */
    private function bindableValue(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => json_encode($value),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            default => $value,
        };
    }
}
