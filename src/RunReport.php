<?php

namespace Shahirul22\LaravelPiiSanitizer;

final class RunReport
{
    /**
     * @param  list<ModelReport>  $models
     */
    public function __construct(
        public readonly array $models,
        public readonly bool $dryRun,
    ) {}

    public function failed(): bool
    {
        foreach ($this->models as $model) {
            if ($model->failed()) {
                return true;
            }
        }

        return false;
    }

    public function rowsSanitized(): int
    {
        return array_sum(array_map(
            fn (ModelReport $model): int => $model->rowsSanitized(),
            $this->models
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'models' => array_map(fn (ModelReport $model): array => $model->toArray(), $this->models),
        ];
    }
}
