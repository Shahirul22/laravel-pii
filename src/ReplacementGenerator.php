<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UniquenessExhaustedException;

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
     * @return array<string, mixed>
     *
     * @throws UniquenessExhaustedException
     * @throws InvalidCategoricalColumnException
     */
    public function forRow(Sanitizer $sanitizer, Model $row, Generator $faker): array
    {
        $table = $row->getTable();
        $modelClass = $row::class;
        $fields = $sanitizer->fields();
        $declared = array_keys($fields);
        $categorical = $sanitizer->categorical();

        $values = [];

        foreach ($declared as $column) {
            $values[$column] = $this->generateValue($column, $fields, $row, $faker, $categorical, $table, $modelClass);
        }

        $constraints = $this->inspector->constraintsAffecting($table, $declared);

        if ($constraints === []) {
            return $values;
        }

        foreach ($constraints as $constraint) {
            $this->tracker->seed($table, $constraint);
        }

        $lastViolated = $constraints[0];

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $violated = [];

            foreach ($constraints as $constraint) {
                $tuple = $this->tupleFor($constraint, $values, $declared, $row);

                if ($this->tracker->isTaken($table, $constraint, $tuple)) {
                    $violated[] = $constraint;
                }
            }

            if ($violated === []) {
                foreach ($constraints as $constraint) {
                    $tuple = $this->tupleFor($constraint, $values, $declared, $row);

                    $this->tracker->claim($table, $constraint, $tuple);
                }

                return $values;
            }

            $lastViolated = $violated[array_key_last($violated)];

            $columnsToRegenerate = array_values(array_unique(array_merge(
                ...array_map(fn (array $constraint): array => array_intersect($constraint, $declared), $violated)
            )));

            foreach ($columnsToRegenerate as $column) {
                $values[$column] = $this->generateValue($column, $fields, $row, $faker, $categorical, $table, $modelClass);
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
     */
    private function generateValue(string $column, array $fields, Model $row, Generator $faker, array $categorical, string $table, string $modelClass): mixed
    {
        if (in_array($column, $categorical, true)) {
            $profile = $this->sampler->profile($table, $column, $modelClass);

            if ($profile !== []) {
                return $this->sampler->sample($table, $column, $faker, $modelClass);
            }
        }

        return $this->resolver->resolve($fields[$column], $row->getAttribute($column), $faker, $row);
    }

    /**
     * Builds the value tuple for one constraint: a declared column
     * contributes its (possibly just-regenerated) candidate value; an
     * undeclared member of a composite constraint contributes the row's
     * current value, held fixed.
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
                : $row->getAttribute($column),
            $constraint
        );
    }
}
