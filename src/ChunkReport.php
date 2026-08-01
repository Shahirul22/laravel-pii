<?php

namespace Shahirul22\LaravelPiiSanitizer;

final class ChunkReport
{
    public function __construct(
        public readonly int $index,
        public readonly int $rowCount,
        public readonly ChunkStatus $status,
        public readonly mixed $firstKey = null,
        public readonly mixed $lastKey = null,
        public readonly ?string $failureMessage = null,
        public readonly ?string $failureClass = null,
    ) {}
}
