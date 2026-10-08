<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UniquenessExhaustedException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsupportedCastException;
use Shahirul22\LaravelPiiSanitizer\Values\Json\JsonPaths;
use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

/**
 * The single seam through which both Phase 4 data-quality mechanisms
 * (uniqueness-preservation and distribution preservation) attach to the
 * existing Phase 2 Sanitizer / ValueDefinitionResolver / ValueGenerator
 * contracts without altering them — see
 * docs/design/data-quality-preservation/data-quality-spec.
 */
class ReplacementGenerator
{
    public function __construct(
        private readonly ValueDefinitionResolver $resolver,
        private readonly UniqueColumnInspector $inspector,
        private readonly UniqueValueTracker $tracker,
        private readonly DistributionSampler $sampler,
        private readonly int $maxAttempts = 100,
    ) {}

    /**
     * Replacement values for one row: column => value, keyed exactly by
     * the columns declared in $sanitizer->fields().
     *
     * A column whose definition is a Keyed value is unique by construction
     * (KeyedResolver guarantees injectivity and avoids the originals), so it
     * is never regenerated here: re-rolling a deterministic value cannot
     * change it. Keyed members of a violated constraint are excluded from the
     * regenerate set, and a violated constraint left with no non-keyed
     * declared member fails fast with keyedConflict(). A constraint tuple
     * holding a NULL in any member (declared or not, whatever its definition
     * type) is exempt from the uniqueness bookkeeping, because MySQL,
     * PostgreSQL and SQLite never treat NULLs as equal in a unique index.
     * Non-keyed columns keep v1's random retry unchanged.
     *
     * @return array<string, mixed>
     *
     * @throws UniquenessExhaustedException
     * @throws InvalidCategoricalColumnException
     * @throws InvalidReplacementValueException
     * @throws UnsupportedCastException
     */
    public function forRow(Sanitizer $sanitizer, Model $row, Generator $faker): array
    {
        $table = $row->getTable();
        $modelClass = $row::class;
        $connection = $row->getConnectionName();
        $fields = $sanitizer->fields();
        $declared = array_keys($fields);

        /** @var list<string> $keyed */
        $keyed = array_keys(array_filter($fields, fn (mixed $definition): bool => $definition instanceof Keyed));
        $categorical = $sanitizer->categorical();

        $values = [];

        foreach ($declared as $column) {
            $values[$column] = $this->generateValue($column, $fields, $row, $faker, $categorical, $table, $modelClass, $connection);
        }

        $constraints = $this->inspector->constraintsAffecting($table, $declared, $connection);

        if ($constraints === []) {
            return $values;
        }

        foreach ($constraints as $constraint) {
            $this->tracker->seed($table, $constraint, $connection);
        }

        $lastViolated = $constraints[0];

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $violated = [];

            foreach ($constraints as $constraint) {
                $tuple = $this->tupleFor($constraint, $values, $declared, $row);

                if (in_array(null, $tuple, true)) {
                    continue;
                }

                if ($this->tracker->isTaken($table, $constraint, $tuple, $connection)) {
                    $violated[] = $constraint;
                }
            }

            if ($violated === []) {
                foreach ($constraints as $constraint) {
                    $tuple = $this->tupleFor($constraint, $values, $declared, $row);

                    if (in_array(null, $tuple, true)) {
                        continue;
                    }

                    $this->tracker->claim($table, $constraint, $tuple, $connection);
                }

                return $values;
            }

            $lastViolated = $violated[array_key_last($violated)];

            foreach ($violated as $constraint) {
                if (array_diff(array_intersect($constraint, $declared), $keyed) === []) {
                    throw UniquenessExhaustedException::keyedConflict($modelClass, array_values(array_intersect($constraint, $declared)), $sanitizer::class, $table);
                }
            }

            $columnsToRegenerate = array_values(array_unique(array_merge(
                ...array_map(fn (array $constraint): array => array_intersect($constraint, $declared), $violated)
            )));

            $columnsToRegenerate = array_values(array_diff($columnsToRegenerate, $keyed));

            foreach ($columnsToRegenerate as $column) {
                $values[$column] = $this->generateValue($column, $fields, $row, $faker, $categorical, $table, $modelClass, $connection);
            }
        }

        $violatedColumns = array_values(array_intersect($lastViolated, $declared));

        throw UniquenessExhaustedException::forConstraint($modelClass, $violatedColumns, $sanitizer::class, $table, $this->maxAttempts);
    }

    /** Clears all run-scoped state. */
    public function reset(): void
    {
        $this->tracker->reset();
        $this->sampler->reset();
    }

    /**
     * Produces one column's candidate value: drawn from the distribution
     * sampler when the column is declared categorical (falling back to the
     * fields() value-definition when the profile is empty, per the
     * degenerate-case rule in the design doc §3.4), otherwise resolved
     * through ValueDefinitionResolver unchanged.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $categorical
     *
     * @throws InvalidCategoricalColumnException
     * @throws UnsupportedCastException
     */
    private function generateValue(string $column, array $fields, Model $row, Generator $faker, array $categorical, string $table, string $modelClass, ?string $connection = null): mixed
    {
        if (in_array($column, $categorical, true)) {
            $profile = $this->sampler->profile($table, $column, $modelClass, $connection);

            if ($profile !== []) {
                return $this->sampler->sample($table, $column, $faker, $modelClass, $connection);
            }
        }

        $current = $this->resolver->usesCurrentValue($fields[$column])
            ? $this->currentValue($row, $column)
            : null;

        if ($current === null && $fields[$column] instanceof JsonPaths && $this->holdsUndecodableJson($row, $column)) {
            throw InvalidReplacementValueException::inColumn($column, InvalidReplacementValueException::invalidJsonDocument());
        }

        // On a cast column Eloquent has already decoded the stored document.
        // A bare scalar root (5, true, 1.5, "hello") cannot be descended into,
        // so every declared path is skipped (the absent/null rule) and the
        // value is returned unchanged. A decoded string is the document's own
        // value, not JSON text, so it must not reach JsonPaths, which would
        // decode it a second time. A column with no cast is a string carrier
        // and keeps JsonPaths' own handling.
        if ($fields[$column] instanceof JsonPaths && $current !== null && ! is_array($current) && $row->hasCast($column)) {
            return $current;
        }

        try {
            $value = $this->resolver->resolve($fields[$column], $current, $faker, $row);
        } catch (InvalidReplacementValueException $e) {
            // A ValueGenerator instance cannot know its column, so name it here.
            if ($fields[$column] instanceof ValueGenerator) {
                throw InvalidReplacementValueException::inColumn($column, $e);
            }

            throw $e;
        }

        if (! $this->isWritableValue($value)) {
            throw InvalidReplacementValueException::unsupportedType($column, get_debug_type($value));
        }

        return $value;
    }

    /**
     * The row's current, cast-decoded value of $column. A value its cast or
     * accessor cannot decode (an encrypted cast holding ciphertext made with
     * another APP_KEY, the normal state after importing a production dump)
     * raises a named error that names the model, the column and the cast,
     * never the stored value. Only called for a definition that reads the
     * current value; a static value or a Faker formatter name skips the read.
     *
     * @throws UnsupportedCastException
     */
    private function currentValue(Model $row, string $column): mixed
    {
        try {
            return $row->getAttribute($column);
        } catch (\Throwable $e) {
            $cast = $row->getCasts()[$column] ?? 'get accessor';

            throw UnsupportedCastException::undecodableValue($row::class, $column, $row->getTable(), is_string($cast) ? $cast : get_debug_type($cast), $e::class);
        }
    }

    /**
     * Whether an array-cast Json::paths() column came back null from Eloquent
     * although its stored value is non-null text that is not valid JSON.
     * fromJson() turns the empty string and malformed text into null without
     * throwing, so without this check the original text would be re-encoded as
     * NULL and lost. SQL NULL and the JSON literal null are not undecodable.
     * An uncast column is a string carrier, which JsonPaths itself validates.
     */
    private function holdsUndecodableJson(Model $row, string $column): bool
    {
        if (! $row->hasCast($column)) {
            return false;
        }

        $raw = $row->getRawOriginal($column);

        if (! is_string($raw)) {
            return false;
        }

        if ($row->hasCast($column, ['encrypted:array', 'encrypted:json'])) {
            // getAttribute() already decrypted this value successfully to reach null.
            $raw = $row->fromEncryptedString($raw);
        }

        if ($raw === '') {
            return true;
        }

        json_decode($raw);

        return json_last_error() !== JSON_ERROR_NONE;
    }

    /**
     * Whether a resolved value is safe to hand to a raw UPDATE: a scalar,
     * null, an array (for JSON/array-cast columns), or a BackedEnum/UnitEnum
     * — not an arbitrary object such as DateTime, which the query builder
     * would otherwise pass through unchecked into the database driver.
     */
    private function isWritableValue(mixed $value): bool
    {
        return $value === null
            || is_scalar($value)
            || is_array($value)
            || $value instanceof \UnitEnum;
    }

    /**
     * Builds the value tuple for one constraint: a declared column
     * contributes its (possibly just-regenerated) candidate value; an
     * undeclared member of a composite constraint contributes the row's
     * raw attribute value (as stored, before any cast), held fixed. Raw, not
     * cast, because UniqueValueTracker::seed() reads the stored values: a
     * boolean cast would give true where the seeded tuple holds 1, and a
     * date cast a Carbon instance where it holds the stored text, so a
     * collision with an original row would be missed.
     *
     * @param  list<string>  $constraint
     * @param  array<string, mixed>  $values
     * @param  list<string>  $declared
     * @return list<mixed>
     */
    private function tupleFor(array $constraint, array $values, array $declared, Model $row): array
    {
        return array_map(
            fn (string $column): mixed => in_array($column, $declared, true)
                ? $values[$column]
                : $row->getAttributes()[$column] ?? null,
            $constraint
        );
    }
}
