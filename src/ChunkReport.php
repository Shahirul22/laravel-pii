<?php

namespace Shahirul22\LaravelPiiSanitizer;

/**
 * $firstKey and $lastKey are a bare scalar (int|string) for a single-column
 * paging identity — unchanged from v1 — or an associative
 * `array<string, mixed>` (column => raw value) for a multi-column identity.
 * See docs/design/engine-hardening/spec §R7 Reporting.
 */
final class ChunkReport
{
    /**
     * @param  int|string|array<string, mixed>|null  $firstKey
     * @param  int|string|array<string, mixed>|null  $lastKey
     */
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
