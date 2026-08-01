<?php

namespace Shahirul22\LaravelPiiSanitizer;

final class ModelReport
{
    /**
     * @param  list<ChunkReport>  $chunks
     * @param  array<string, int>  $columnCounts  column => rows whose value changed / would change
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly string $table,
        public readonly int $chunkSize,
        public readonly bool $automaticChunkSize,
        public readonly int $rowsScanned,
        public readonly int $expectedChunks,
        public readonly array $chunks,
        public readonly array $columnCounts = [],
        public readonly ?string $skipReason = null,
    ) {}

    public function failed(): bool
    {
        foreach ($this->chunks as $chunk) {
            if ($chunk->status !== ChunkStatus::Completed) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ChunkReport> */
    public function completedChunks(): array
    {
        return array_values(array_filter(
            $this->chunks,
            fn (ChunkReport $chunk): bool => $chunk->status === ChunkStatus::Completed
        ));
    }

    public function rowsSanitized(): int
    {
        return array_sum(array_map(
            fn (ChunkReport $chunk): int => $chunk->rowCount,
            $this->completedChunks()
        ));
    }

    public function chunksNotAttempted(): int
    {
        return max(0, $this->expectedChunks - count($this->chunks));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'modelClass' => $this->modelClass,
            'table' => $this->table,
            'chunkSize' => $this->chunkSize,
            'automaticChunkSize' => $this->automaticChunkSize,
            'rowsScanned' => $this->rowsScanned,
            'expectedChunks' => $this->expectedChunks,
            'chunks' => array_map(fn (ChunkReport $chunk): array => [
                'index' => $chunk->index,
                'rowCount' => $chunk->rowCount,
                'status' => $chunk->status->value,
                'firstKey' => $chunk->firstKey,
                'lastKey' => $chunk->lastKey,
                'failureClass' => $chunk->failureClass,
                'failureMessage' => $chunk->failureMessage,
            ], $this->chunks),
            'columnCounts' => $this->columnCounts,
            'skipReason' => $this->skipReason,
            'rowsSanitized' => $this->rowsSanitized(),
            'chunksNotAttempted' => $this->chunksNotAttempted(),
        ];
    }
}
