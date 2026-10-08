<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\DataQualityGuardContract;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Values\Json\JsonPaths;
use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

/**
 * Fail-fast validation of a Sanitizer's categorical() declaration (R4.2),
 * fired at the same choke point SchemaGuard uses — before any row is read
 * or written. A Json::paths() column cannot be categorical: sampling would
 * replace the whole document with another row's original.
 */
class DataQualityGuard implements DataQualityGuardContract
{
    public function __construct(
        private readonly UniqueColumnInspector $inspector,
    ) {}

    /**
     * @throws InvalidCategoricalColumnException
     * @throws InvalidConfigurationException
     */
    public function assertValid(Sanitizer $sanitizer, Model|string $model): void
    {
        $categorical = $sanitizer->categorical();

        if ($categorical === []) {
            return;
        }

        $modelClass = is_object($model) ? $model::class : $model;

        if (! is_object($model) && ! class_exists($modelClass) && ! app()->bound($modelClass)) {
            throw InvalidConfigurationException::invalidModelClass($modelClass);
        }

        $instance = is_object($model) ? $model : app($modelClass);

        if (! $instance instanceof Model) {
            throw InvalidConfigurationException::invalidModelClass($modelClass);
        }

        $fields = $sanitizer->fields();

        foreach ($categorical as $column) {
            if (! array_key_exists($column, $fields)) {
                throw InvalidCategoricalColumnException::notDeclared($modelClass, $column, $sanitizer::class);
            }

            if ($fields[$column] instanceof Keyed) {
                throw InvalidCategoricalColumnException::keyed($modelClass, $column, $sanitizer::class);
            }

            if ($fields[$column] instanceof JsonPaths) {
                throw InvalidCategoricalColumnException::jsonPaths($modelClass, $column, $sanitizer::class);
            }
        }

        $table = $instance->getTable();
        $constraints = $this->inspector->uniqueConstraints($table, $instance->getConnectionName());

        foreach ($categorical as $column) {
            foreach ($constraints as $constraint) {
                if (in_array($column, $constraint, true)) {
                    throw InvalidCategoricalColumnException::uniqueConstrained($modelClass, $column, $sanitizer::class, $table);
                }
            }
        }

        // A categorical value is sampled from the raw stored values
        // (DistributionSampler reads through the query builder), and the
        // runner then encodes every cast-bearing column through the model's
        // cast: the sample would be encoded twice. Checked after the unique
        // check so a primary key, which carries Eloquent's implicit key cast,
        // keeps its unique-constraint message.
        foreach ($categorical as $column) {
            if ($instance->hasCast($column) || $instance->hasSetMutator($column) || $instance->hasAttributeSetMutator($column)) {
                throw InvalidCategoricalColumnException::castBearing($modelClass, $column, $sanitizer::class);
            }
        }
    }
}
