<?php

namespace Shahirul22\LaravelPiiSanitizer;

/**
 * Emitted by SanitizationRunner via RunOptions::$onProgress. $chunk is null
 * on the model-start event and a real ChunkReport on every chunk-complete
 * event (for both Completed and RolledBack chunks). $rowsProcessed is the
 * running row total scanned for the current model at the moment the event
 * fires (0 on model-start); it is computed after the completed chunk's rows
 * are added to the running scan total, so it reflects completed work.
 */
final class ProgressEvent
{
    public function __construct(
        public readonly string $modelClass,
        public readonly string $table,
        public readonly int $expectedChunks,
        public readonly ?ChunkReport $chunk = null,
        public readonly int $rowsProcessed = 0,
    ) {}
}
