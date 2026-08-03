<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;

/**
 * The single new input channel for a sanitization run — confines every
 * Phase 5 run parameter to one type that the Phase 2-4 contracts never see.
 */
final class RunOptions
{
    /**
     * @param  list<class-string<Model>>|null  $models
     * @param  (\Closure(ProgressEvent): void)|null  $onProgress
     */
    public function __construct(
        public readonly ?int $chunkSize = null,
        public readonly bool $dryRun = false,
        public readonly bool $force = false,
        public readonly bool $confirmed = false,
        public readonly ?array $models = null,
        public readonly ?\Closure $onProgress = null,
    ) {}
}
