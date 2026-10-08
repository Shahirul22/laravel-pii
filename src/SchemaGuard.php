<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Faker\Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\SchemaGuardContract;
use Shahirul22\LaravelPiiSanitizer\Exceptions\ConstraintViolationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
use Shahirul22\LaravelPiiSanitizer\Values\Json\JsonPaths;
use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

class SchemaGuard implements SchemaGuardContract
{
    /**
     * Keyed by "<connection-name>.<canonical-table>" so a second connection's schema never poisons or is masked by the first's.
     *
     * @var array<string, list<string>>
     */
    private array $columnCache = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ColumnConstraintInspector $constraints,
        private readonly ConstraintValidator $validator,
        private readonly ValueDefinitionResolver $definitions,
        private readonly CastAwareEncoder $encoder,
        private readonly Generator $faker,
        private readonly UniqueColumnInspector $uniqueInspector,
        private readonly ForeignKeyInspector $foreignKeys,
    ) {}

    /**
     * @throws UnsafeColumnException
     * @throws InvalidConfigurationException
     * @throws ConstraintViolationException
     */
    public function assertSafe(Sanitizer $sanitizer, Model|string $model): void
    {
        $modelClass = is_object($model) ? $model::class : $model;
        $instance = is_object($model) ? $model : app($modelClass);

        if (! $instance instanceof Model) {
            throw InvalidConfigurationException::invalidModelClass($modelClass);
        }

        $fields = $sanitizer->fields();
        $mirrors = $sanitizer->mirrors();

        $this->assertMirrorDeclarations($sanitizer, $instance, $modelClass, $fields, $mirrors);

        // R1.3: the FK, inbound-FK and primary-key rejections below are
        // skipped only for columns this sanitizer itself lists in mirrors().
        // With mirrors() empty, in_array() is always false and every check
        // runs exactly as in v1 (R1.5).
        $optedIn = array_keys($mirrors);

        $columns = array_keys($fields);

        if ($columns === []) {
            return;
        }

        $table = $instance->getTable();
        $connection = $this->db->connection($instance->getConnectionName());

        $existing = $this->tableColumns($connection, $table);

        foreach ($columns as $column) {
            if (! in_array($column, $existing, true)) {
                throw UnsafeColumnException::unknownColumn($modelClass, $column, $sanitizer::class, $table);
            }
        }

        $outbound = $this->foreignKeys->outboundForeignKeyColumns($connection, $table);

        foreach ($columns as $column) {
            if (in_array($column, $optedIn, true)) {
                continue;
            }

            if (in_array($column, $outbound, true)) {
                throw UnsafeColumnException::outboundForeignKey(
                    $modelClass,
                    $column,
                    $sanitizer::class,
                    $table,
                    $this->foreignKeys->outboundTarget($connection, $table, $column)
                );
            }
        }

        $inbound = $this->foreignKeys->inboundReferencedColumns($connection, $table);

        foreach ($columns as $column) {
            if (in_array($column, $optedIn, true)) {
                continue;
            }

            if (array_key_exists($column, $inbound)) {
                throw UnsafeColumnException::inboundReference(
                    $modelClass,
                    $column,
                    $sanitizer::class,
                    $table,
                    $inbound[$column]
                );
            }
        }

        // The primary key drives the read/paging path (chunkById() for a
        // single column, chunkByKeyset() for a composite one); rewriting any
        // of its columns mid-run would corrupt the chunk cursor for that
        // same read. Checked last so a primary key that is also an
        // inbound-referenced column (the common case) still surfaces the
        // more specific FK message above. Composite-aware (R7.1): every
        // column of a multi-column primary key is protected, not only the
        // first, and a model-declared key name not present in the schema PK
        // (e.g. a TableRow's null key name) is included too when non-empty.
        // An opted-in primary-key column is skipped here: it is paged on a
        // disjoint identity instead (PagingKeyResolver).
        $protected = $this->uniqueInspector->primaryKey($table, $instance->getConnectionName()) ?? [];

        $modelKeyName = $instance->getKeyName();

        // Model::getKeyName()'s @return string PHPDoc does not reflect
        // reality for a TableRow target (its $primaryKey is intentionally
        // null — design §R7 Model-less table target), so it can genuinely
        // be null at runtime despite the declared type.
        // @phpstan-ignore function.alreadyNarrowedType (getKeyName() can be null for a TableRow with $primaryKey = null, despite its string PHPDoc)
        if (is_string($modelKeyName) && $modelKeyName !== '' && ! in_array($modelKeyName, $protected, true)) {
            $protected[] = $modelKeyName;
        }

        foreach ($columns as $column) {
            if (in_array($column, $optedIn, true)) {
                continue;
            }

            if (in_array($column, $protected, true)) {
                throw UnsafeColumnException::primaryKey($modelClass, $column, $sanitizer::class, $table);
            }
        }

        $this->assertJsonPathsDeclarable($sanitizer, $instance, $modelClass, $table, $fields);

        $this->assertStaticValuesWritable($sanitizer, $instance, $modelClass, $table, $columns);
    }

    /**
     * Per-target mirror checks 1 and 2 (docs/design/referenced-identifier-structured-column-sanitization/spec, Boot-time checks). Pure: reads declarations only.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, list<string>>  $mirrors
     *
     * @throws InvalidConfigurationException
     */
    private function assertMirrorDeclarations(Sanitizer $sanitizer, Model $instance, string $modelClass, array $fields, array $mirrors): void
    {
        if ($mirrors === []) {
            return;
        }

        foreach ($mirrors as $column => $list) {
            if (! array_key_exists($column, $fields)) {
                throw InvalidConfigurationException::invalidMirrorDeclaration($sanitizer::class, $column, 'the column is not declared in fields()');
            }

            if ($list === []) {
                throw InvalidConfigurationException::invalidMirrorDeclaration($sanitizer::class, $column, 'the mirror list is empty');
            }

            foreach ($list as $entry) {
                $dot = strrpos($entry, '.');

                if ($dot === false || $dot === 0 || $dot === strlen($entry) - 1) {
                    throw InvalidConfigurationException::invalidMirrorDeclaration($sanitizer::class, $column, "mirror \"{$entry}\" is not of the form table.column");
                }

                if ($entry === $instance->getTable().'.'.$column) {
                    throw InvalidConfigurationException::invalidMirrorDeclaration($sanitizer::class, $column, "mirror \"{$entry}\" names the column itself");
                }
            }
        }

        foreach (array_keys($mirrors) as $column) {
            if (! $fields[$column] instanceof Keyed) {
                throw InvalidConfigurationException::mirroredColumnNotKeyed($modelClass, $column, $sanitizer::class);
            }
        }
    }

    /**
     * R2 boot check (docs/design/referenced-identifier-structured-column-sanitization/spec, "Boot check SchemaGuard::assertJsonPathsDeclarable()"):
     * a Json::paths() column has no get/set mutator, no cast or an allow-listed array cast, and a json or string column family.
     * Every static inner definition must also be JSON-writable (spec "Boot rules for path definitions").
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws InvalidConfigurationException
     */
    private function assertJsonPathsDeclarable(Sanitizer $sanitizer, Model $instance, string $modelClass, string $table, array $fields): void
    {
        $columns = array_keys(array_filter($fields, fn (mixed $definition): bool => $definition instanceof JsonPaths));

        // No path map: return before any schema query so a flat-only target's query sequence is unchanged (AC-13).
        if ($columns === []) {
            return;
        }

        $map = $this->constraints->constraintsFor($table, $instance->getConnectionName());

        foreach ($columns as $column) {
            $reason = $this->jsonPathsRejection($instance, $column, $map);

            if ($reason !== null) {
                throw InvalidConfigurationException::jsonPathsUnsupportedColumn($modelClass, $column, $sanitizer::class, $table, $reason);
            }

            $pathMap = $fields[$column];
            assert($pathMap instanceof JsonPaths);

            foreach ($pathMap->definitions() as $path => $definition) {
                if (! $this->definitions->isStatic($definition, $this->faker)) {
                    continue;
                }

                $type = JsonPaths::unwritableType($definition);

                if ($type !== null) {
                    throw InvalidConfigurationException::jsonPathStaticValueNotWritable($modelClass, $column, (string) $path, $sanitizer::class, $type);
                }
            }
        }
    }

    /**
     * Why a path map cannot be declared on $column, or null when it can.
     *
     * @param  array<string, ColumnConstraints>  $map
     */
    private function jsonPathsRejection(Model $instance, string $column, array $map): ?string
    {
        if ($instance->hasGetMutator($column) || $instance->hasAttributeGetMutator($column) || $instance->hasSetMutator($column) || $instance->hasAttributeSetMutator($column)) {
            return 'it has a get or set mutator';
        }

        if ($instance->hasCast($column) && ! $instance->hasCast($column, ['array', 'json', 'json:unicode', 'encrypted:array', 'encrypted:json'])) {
            $cast = $instance->getCasts()[$column];

            return 'its cast "'.(is_string($cast) ? $cast : get_debug_type($cast)).'" is not one of: none, array, json, json:unicode, encrypted:array, encrypted:json';
        }

        $family = isset($map[$column]) ? $map[$column]->family : 'other';

        if (! in_array($family, ['json', 'string'], true)) {
            return "its column type family is \"{$family}\", not json or string";
        }

        return null;
    }

    /**
     * R6.3 boot validation: a definition determinable in full from the
     * declaration alone (static per ValueDefinitionResolver::isStatic()),
     * on a column that is neither cast-bearing (§R5 — its stored value is
     * only known after the per-row encode step) nor categorical (its
     * sampled value comes from DistributionSampler, not the static
     * definition), is validated once here rather than waiting for a row to
     * be read. Every other case is validated per-row inside
     * SanitizationRunner::processChunk().
     *
     * @param  list<string>  $columns
     *
     * @throws ConstraintViolationException
     */
    private function assertStaticValuesWritable(Sanitizer $sanitizer, Model $instance, string $modelClass, string $table, array $columns): void
    {
        $fields = $sanitizer->fields();
        $categorical = $sanitizer->categorical();
        $cast = $this->encoder->classify($instance, $columns);

        $candidates = array_values(array_filter(
            $columns,
            fn (string $column): bool => $this->definitions->isStatic($fields[$column], $this->faker)
                && ! in_array($column, $cast, true)
                && ! in_array($column, $categorical, true)
        ));

        if ($candidates === []) {
            return;
        }

        $map = $this->constraints->constraintsFor($table, $instance->getConnectionName());

        foreach ($candidates as $column) {
            if (! isset($map[$column])) {
                continue;
            }

            $this->validator->assertWritable($modelClass, $table, $map[$column], SanitizationRunner::bindableValue($fields[$column]));
        }
    }

    /**
     * @return list<string>
     */
    private function tableColumns(Connection $connection, string $table): array
    {
        $cacheKey = $this->cacheKey($connection, $table);

        if (isset($this->columnCache[$cacheKey])) {
            return $this->columnCache[$cacheKey];
        }

        /** @var list<array{name: string, type: string, type_name: string, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation: array<string, mixed>|null}> $columns */
        $columns = $connection->getSchemaBuilder()->getColumns($table);

        return $this->columnCache[$cacheKey] = array_column($columns, 'name');
    }

    private function cacheKey(Connection $connection, string $table): string
    {
        return $connection->getName().'.'.$this->foreignKeys->canonicalTableName($connection, $table);
    }
}
