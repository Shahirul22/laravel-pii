<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Faker\Generator;
use Illuminate\Database\DatabaseManager;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;

/**
 * Derives and samples from a categorical column's original value
 * distribution (R4.2), so replacement values approximate the column's
 * pre-sanitization frequencies rather than being drawn from an unrelated
 * value space.
 */
class DistributionSampler
{
    public const MAX_CATEGORIES = 1000;

    /** @var array<string, array<string, array{value: mixed, count: int}>> */
    private array $profileCache = [];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The column's original value distribution: value => row count.
     * Computed once per (table, column) per run and cached — the caching
     * is load-bearing for correctness, not just performance (§3.3.1 of the
     * design doc): re-profiling mid-run would drift the sanitization
     * target as rows are rewritten.
     *
     * @return array<string, array{value: mixed, count: int}>
     *
     * @throws InvalidCategoricalColumnException
     */
    public function profile(string $table, string $column, ?string $modelClass = null, ?string $connection = null): array
    {
        $key = ($connection ?? '').'.'.$table.'.'.$column;

        if (isset($this->profileCache[$key])) {
            return $this->profileCache[$key];
        }

        // LIMIT one past the cap so the cardinality check below fails fast
        // off a bounded result set, rather than materializing every group
        // for a high-cardinality column before discovering it's not
        // categorical (the full aggregation cost was previously paid
        // either way).
        $rows = $this->db->connection($connection)->table($table)
            ->select($column)
            ->selectRaw('count(*) as aggregate')
            ->groupBy($column)
            ->limit(self::MAX_CATEGORIES + 1)
            ->get();

        if ($rows->count() > self::MAX_CATEGORIES) {
            throw InvalidCategoricalColumnException::tooManyCategories($modelClass ?? $table, $column, $table, $rows->count());
        }

        $profile = [];

        foreach ($rows as $row) {
            $value = $row->{$column};
            $profile[$this->identityKey($value)] = [
                'value' => $value,
                'count' => (int) $row->aggregate,
            ];
        }

        return $this->profileCache[$key] = $profile;
    }

    /**
     * A stable, collision-resistant array-key for a raw column value. Not
     * json_encode($value) directly: it returns false — silently coerced to
     * the array key '' — for a string containing malformed/invalid UTF-8
     * byte sequences, which a categorical column can plausibly hold when
     * sourced from a legacy-encoded production dump (exactly the kind of
     * input this package exists to sanitize). Distinct invalid-UTF-8 values
     * would otherwise collapse into the same '' bucket, corrupting the
     * profiled distribution. json_last_error() disambiguates a genuine
     * encoding failure from json_encode() legitimately returning the
     * 3-byte string "false" for the boolean false.
     */
    private function identityKey(mixed $value): string
    {
        $encoded = json_encode($value);

        if ($encoded !== false || json_last_error() === JSON_ERROR_NONE) {
            return $encoded;
        }

        return 'raw:'.md5(serialize($value));
    }

    /**
     * One draw from the profile, weighted by the original frequencies
     * (cumulative-weight / roulette-wheel selection). Returns null when
     * the profile is empty; callers detect degeneracy via profile() being
     * empty, not via this return value.
     *
     * @throws InvalidCategoricalColumnException
     */
    public function sample(string $table, string $column, Generator $faker, ?string $modelClass = null, ?string $connection = null): mixed
    {
        $profile = $this->profile($table, $column, $modelClass, $connection);

        if ($profile === []) {
            return null;
        }

        $total = array_sum(array_column($profile, 'count'));

        $r = $faker->numberBetween(1, $total);

        $cumulative = 0;
        $last = null;

        foreach ($profile as $entry) {
            $cumulative += $entry['count'];
            $last = $entry['value'];

            if ($cumulative >= $r) {
                return $entry['value'];
            }
        }

        // Unreachable given $r <= $total, but keeps every path returning a
        // value from the profile.
        return $last;
    }

    /** Clears all cached profiles. */
    public function reset(): void
    {
        $this->profileCache = [];
    }
}
