<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\DataQualityGuardContract;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;

/**
 * Fail-fast validation of a Sanitizer's categorical() declaration (R4.2),
 * fired at the same choke point SchemaGuard uses — before any row is read
 * or written.
 */
class DataQualityGuard implements DataQualityGuardContract
{
    public function __construct(
        private readonly UniqueColumnInspector $inspector,
    ) {}

    /**
     * @throws InvalidCategoricalColumnException
     */
    public function assertValid(Sanitizer $sanitizer, Model|string $model): void
    {
        $categorical = $sanitizer->categorical();

        if ($categorical === []) {
            return;
        }

        $modelClass = is_object($model) ? $model::class : $model;
        $instance = is_object($model) ? $model : app($modelClass);

        if (! $instance instanceof Model) {
            throw InvalidConfigurationException::invalidModelClass($modelClass);
        }

        $fields = $sanitizer->fields();

        foreach ($categorical as $column) {
            if (! array_key_exists($column, $fields)) {
                throw InvalidCategoricalColumnException::notDeclared($modelClass, $column, $sanitizer::class);
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
    }
}
