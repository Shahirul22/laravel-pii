<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\EnvironmentGuardContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;

/**
 * Walks every configured model in automatically-sized (or manually
 * overridden) chunks, writing each chunk's replacement values inside a
 * single transaction, and never throws for a mid-chunk failure — see
 * docs/design/execution-engine-and-safety/execution-engine-spec.
 */
class SanitizationRunner
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

        /** @var list<class-string<Model>> $modelClasses */
        $modelClasses = $options->models ?? config('pii.models', []);

        $reports = [];

        foreach ($modelClasses as $modelClass) {
            $modelReport = $this->runModel($modelClass, $options);
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
     * Deliberately not public: this is only reachable through the guarded
     * and reset entry point run() above, so the environment guard and
     * generator reset can never be bypassed by calling into the engine
     * directly.
     */
    protected function runModel(string $modelClass, RunOptions $options): ModelReport
    {
        $model = app($modelClass);

        assert($model instanceof Model);

        $sanitizer = $this->resolver->resolve($model);

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

        $chunks = [];
        $rowsScanned = 0;
        /** @var array<string, int> $columnCounts */
        $columnCounts = [];
        $index = 0;

        $model->newQuery()->chunkById($size, function ($rows) use (
            &$chunks, &$rowsScanned, &$columnCounts, &$index, $sanitizer, $options
        ) {
            $index++;

            $chunk = $this->processChunk($sanitizer, $rows->all(), $index, $options, $columnCounts);

            $chunks[] = $chunk;
            $rowsScanned += $rows->count();

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
            foreach ($rows as $row) {
                $values = $this->generator->forRow($sanitizer, $row, $this->faker);

                foreach ($values as $column => $value) {
                    if ($value !== $row->getAttribute($column)) {
                        $chunkColumnCounts[$column] = ($chunkColumnCounts[$column] ?? 0) + 1;
                    }
                }

                if (! $options->dryRun) {
                    $connection->table($table)->where($key, $row->getKey())->update($values);
                }
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
}
