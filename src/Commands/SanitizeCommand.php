<?php

namespace Shahirul22\LaravelPiiSanitizer\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\ChunkReport;
use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeEnvironmentException;
use Shahirul22\LaravelPiiSanitizer\ModelReport;
use Shahirul22\LaravelPiiSanitizer\ProgressEvent;
use Shahirul22\LaravelPiiSanitizer\RunOptions;
use Shahirul22\LaravelPiiSanitizer\RunReport;
use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
use Symfony\Component\Console\Helper\ProgressBar;

class SanitizeCommand extends Command
{
    protected $signature = 'pii:sanitize
        {--dry-run : Report what would change without writing anything to the database}
        {--force : Allow the run to proceed outside the allowed environments}
        {--chunk= : Rows per chunk; overrides config pii.chunk.size and automatic sizing}
        {--model=* : Fully-qualified model class to sanitize; repeatable, defaults to config pii.models}';

    protected $description = 'Sanitize PII columns in the database.';

    private ?ProgressBar $bar = null;

    public function handle(SanitizationRunner $runner): int
    {
        $raw = $this->option('chunk');

        if ($raw !== null && ! ($this->isPositiveIntegerString((string) $raw))) {
            $this->components->error(sprintf('The --chunk option must be a positive integer, got "%s".', $raw));

            return self::FAILURE;
        }

        $chunkSize = $raw === null ? null : (int) $raw;

        /** @var list<string> $models */
        $models = $this->option('model');
        $models = $models === [] ? null : $models;

        $confirmed = $this->confirmedForEnvironment();

        $options = new RunOptions(
            chunkSize: $chunkSize,
            dryRun: (bool) $this->option('dry-run'),
            force: (bool) $this->option('force'),
            confirmed: $confirmed,
            models: $models,
            onProgress: $this->progressHandler(),
        );

        $resolvedModels = $options->models ?? config('pii.models', []);

        if (! is_array($resolvedModels)) {
            $this->components->error(InvalidConfigurationException::modelsNotAList()->getMessage());

            return self::FAILURE;
        }

        if ($options->dryRun) {
            $this->components->info('Dry run — no data will be written.');
        }

        $this->components->info(sprintf('Sanitizing %d model(s).', count($resolvedModels)));

        try {
            $report = $runner->run($options);
        } catch (UnsafeEnvironmentException|InvalidConfigurationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->bar?->finish();
        $this->bar = null;
        $this->newLine(2);

        if ($report->failed()) {
            $this->renderFailureSummary($report, $resolvedModels);

            return self::FAILURE;
        }

        if ($report->dryRun) {
            $this->renderDryRunSummary($report);

            return self::SUCCESS;
        }

        $this->renderSuccessSummary($report);

        return self::SUCCESS;
    }

    private function isPositiveIntegerString(string $value): bool
    {
        return ctype_digit($value) && (int) $value >= 1;
    }

    /**
     * Display-only read of the same allow-list/environment the guard itself
     * consults; it decides only whether to prompt at all and never
     * substitutes for EnvironmentGuard::assertRunnable(), which remains the
     * sole authority on whether the run actually proceeds.
     */
    private function confirmedForEnvironment(): bool
    {
        $allowed = config('pii.environments', ['local', 'testing']);
        $environment = (string) $this->laravel->environment();

        if (is_array($allowed) && in_array($environment, array_map(strval(...), $allowed), true)) {
            return false;
        }

        if (! $this->option('force')) {
            return false;
        }

        return $this->confirm(
            sprintf(
                'You are about to sanitize PII in the "%s" environment. This is a development-time tool and this environment is not in your configured allow-list. Continue?',
                $environment
            ),
            false
        );
    }

    private function progressHandler(): \Closure
    {
        return function (ProgressEvent $event): void {
            if ($event->chunk === null) {
                if ($event->expectedChunks === 0) {
                    if ($this->bar !== null) {
                        $this->bar->finish();
                        $this->newLine();
                        $this->bar = null;
                    }

                    $this->components->twoColumnDetail($event->modelClass, '0 rows');

                    return;
                }

                if ($this->bar !== null) {
                    $this->bar->finish();
                    $this->newLine();
                }

                $this->bar = $this->output->createProgressBar($event->expectedChunks);
                $this->bar->setFormat(sprintf('%s: %%current%%/%%max%% chunks [%%bar%%] %%percent:3s%%%%', $event->modelClass));
                $this->bar->start();

                return;
            }

            $this->bar?->advance();
        };
    }

    private function renderSuccessSummary(RunReport $report): void
    {
        foreach ($report->models as $model) {
            if ($model->skipReason === 'no-sanitizer') {
                $this->components->twoColumnDetail($model->modelClass, 'skipped — no sanitizer');
            } else {
                $this->components->twoColumnDetail($model->modelClass, number_format($model->rowsSanitized()));
            }
        }

        $this->components->twoColumnDetail('Total', number_format($report->rowsSanitized()));

        $this->components->info('Sanitize run completed successfully.');
    }

    private function renderDryRunSummary(RunReport $report): void
    {
        foreach ($report->models as $model) {
            if ($model->skipReason === 'no-sanitizer') {
                $this->components->twoColumnDetail($model->modelClass, 'skipped — no sanitizer');

                continue;
            }

            $this->components->twoColumnDetail($model->modelClass, sprintf('%s rows would be sanitized', number_format($model->rowsSanitized())));
        }

        foreach ($report->models as $model) {
            if ($model->columnCounts === []) {
                continue;
            }

            $this->line(sprintf('  Column breakdown for %s:', $model->modelClass));

            foreach ($model->columnCounts as $column => $count) {
                $this->components->twoColumnDetail($column, sprintf('%s rows would change', number_format($count)));
            }
        }

        $this->components->warn('Dry run complete — nothing was written to the database.');
    }

    /**
     * @param  list<class-string<Model>>  $resolvedModels
     */
    private function renderFailureSummary(RunReport $report, array $resolvedModels): void
    {
        $failing = null;

        foreach ($report->models as $model) {
            if ($model->failed()) {
                $failing = $model;
                break;
            }
        }

        if (! $failing instanceof ModelReport) {
            return;
        }

        $this->components->error(sprintf('Sanitize run failed while processing %s.', $failing->modelClass));

        $failingChunk = null;

        foreach ($failing->chunks as $chunk) {
            if ($chunk->status !== ChunkStatus::Completed) {
                $failingChunk = $chunk;
                break;
            }
        }

        if ($failingChunk instanceof ChunkReport) {
            $this->line(sprintf(
                '  Chunk #%d failed (keys %s–%s):',
                $failingChunk->index,
                (string) $failingChunk->firstKey,
                (string) $failingChunk->lastKey
            ));
            $this->line(sprintf('    %s: %s', $failingChunk->failureClass, $failingChunk->failureMessage));
        }

        $this->components->twoColumnDetail('Rows sanitized:', number_format($failing->rowsSanitized()));
        $this->components->twoColumnDetail('Chunks completed:', sprintf('%d of %d', count($failing->completedChunks()), $failing->expectedChunks));
        $this->components->twoColumnDetail('Chunks not attempted:', (string) $failing->chunksNotAttempted());

        $attemptedClasses = array_map(fn (ModelReport $model): string => $model->modelClass, $report->models);
        $notAttempted = array_values(array_diff($resolvedModels, $attemptedClasses));

        foreach ($notAttempted as $class) {
            $this->components->twoColumnDetail($class, 'not attempted — a prior model failed');
        }

        $notAttemptedCount = count($notAttempted);

        $this->components->error(sprintf('%d model failed; %d model(s) were not attempted. See above for detail.', 1, $notAttemptedCount));
    }
}
