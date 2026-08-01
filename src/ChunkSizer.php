<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the chunk size a model's read loop iterates with, per the
 * precedence chain and automatic-sizing algorithm fixed in
 * docs/design/execution-engine-and-safety/execution-engine-spec §2.3.
 */
class ChunkSizer
{
    public const DEFAULT_MIN = 500;

    public const DEFAULT_MAX = 5000;

    public const DEFAULT_TARGET_CHUNKS = 20;

    private bool $wasAutomatic = false;

    /** @var array<string, int> */
    private array $counts = [];

    public function sizeFor(Model $model, RunOptions $options): int
    {
        if ($options->chunkSize !== null && $options->chunkSize >= 1) {
            $this->wasAutomatic = false;

            return $options->chunkSize;
        }

        $configuredSize = config('pii.chunk.size');

        if (is_numeric($configuredSize) && (int) $configuredSize >= 1) {
            $this->wasAutomatic = false;

            return (int) $configuredSize;
        }

        $this->wasAutomatic = true;

        $count = $this->countFor($model);
        $min = $this->intConfig('pii.chunk.min', self::DEFAULT_MIN);

        if ($count <= $min) {
            return max($count, 1);
        }

        $targetChunks = $this->intConfig('pii.chunk.target_chunks', self::DEFAULT_TARGET_CHUNKS);
        $max = $this->intConfig('pii.chunk.max', self::DEFAULT_MAX);

        $candidate = (int) ceil($count / $targetChunks);

        return max($min, min($max, $candidate));
    }

    public function wasAutomatic(): bool
    {
        return $this->wasAutomatic;
    }

    /** Memoized COUNT(*) per table, so at most one is issued per table per run. */
    public function countFor(Model $model): int
    {
        $table = $model->getTable();

        if (! array_key_exists($table, $this->counts)) {
            $this->counts[$table] = (int) $model->newQuery()->toBase()->count();
        }

        return $this->counts[$table];
    }

    /**
     * A partially-published `chunk` config array yields `null` sub-keys
     * that config()'s own default argument does not catch, so every
     * sub-key read is coerced back to its constant explicitly.
     */
    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }
}
