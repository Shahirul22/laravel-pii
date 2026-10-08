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
    /** Cast types (the part before any ":argument") under which a raw stored value keeps its meaning. */
    private const SAMPLE_PRESERVING_CASTS = [
        'int', 'integer', 'real', 'float', 'double', 'decimal', 'string', 'bool', 'boolean',
        'date', 'datetime', 'custom_datetime', 'immutable_date', 'immutable_datetime', 'immutable_custom_datetime', 'timestamp',
    ];

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

        // A missing class, or an existing class that is not a model, would
        // otherwise surface as the container's raw BindingResolutionException.
        if (! is_object($model) && (class_exists($modelClass) ? ! is_subclass_of($modelClass, Model::class) : ! app()->bound($modelClass))) {
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
        // cast. Refused only where that second encoding changes the value's
        // meaning. Checked after the unique check so a primary key, which
        // carries Eloquent's implicit key cast, keeps its unique-constraint
        // message.
        foreach ($categorical as $column) {
            if ($this->encodesSampleAgain($instance, $column)) {
                throw InvalidCategoricalColumnException::castBearing($modelClass, $column, $sanitizer::class);
            }
        }
    }

    /**
     * Whether writing a raw stored value back through the column's cast or
     * set mutator would change what it means. A scalar, decimal, date or
     * enum cast stores a raw value with the same meaning (an enum value maps
     * to its own case, a stored date parses to the same date), so only
     * those casts are allowed. Every other cast is refused: array, json,
     * object and collection casts would wrap the stored JSON text in a JSON
     * string, encrypted casts would encrypt a ciphertext again, hashed would
     * hash a hash, and a custom cast class or a set mutator is unknown.
     */
    private function encodesSampleAgain(Model $instance, string $column): bool
    {
        if ($instance->hasSetMutator($column) || $instance->hasAttributeSetMutator($column)) {
            return true;
        }

        if (! $instance->hasCast($column)) {
            return false;
        }

        $cast = $instance->getCasts()[$column];

        if (! is_string($cast)) {
            return true;
        }

        if (enum_exists($cast)) {
            return false;
        }

        $type = strtolower(trim(explode(':', $cast, 2)[0]));

        return ! in_array($type, self::SAMPLE_PRESERVING_CASTS, true);
    }
}
