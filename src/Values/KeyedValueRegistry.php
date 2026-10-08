<?php

namespace Shahirul22\LaravelPiiSanitizer\Values;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Sanitizer;
use Shahirul22\LaravelPiiSanitizer\UniqueColumnInspector;
use Shahirul22\LaravelPiiSanitizer\Values\Json\JsonPaths;

/**
 * Run-scoped state behind Keyed collision resolution. The runner calls
 * register() for every target in its registration pass, before any row is
 * read, and reset() at the top of each run.
 *
 * register() only collects declarations: the shape signature and the
 * (connection, table, column) bindings of each namespace. There is no
 * relational inference. A namespace is unique-bound when at least one of its
 * bindings is a member of a unique or primary constraint; only a
 * unique-bound namespace resolves through this registry. For each
 * unique-bound binding the column's distinct original values are streamed
 * once and become the forbidden set (the union across all of the namespace's
 * unique-bound bindings), so a replacement never equals an original that a
 * not-yet-sanitized row still holds.
 *
 * Memory is O(distinct inputs per unique-bound namespace) for the memo plus
 * O(distinct originals per unique-bound binding) for the forbidden set, the
 * same order as v1's UniqueValueTracker seeding. Inputs are held only as
 * 16-byte keyed digests, never as plaintext.
 *
 * All comparisons use comparisonKey(): the lowercased string form, so an
 * integer column (123 versus '123') and a case-insensitive collation (AB12
 * versus ab12) behave as the database sees them. Collations that fold
 * accents or trailing spaces are not covered.
 *
 * register() also scans each Json::paths() definition for Keyed path
 * definitions and records them as non-unique path bindings, so a path and a
 * flat column in one namespace map the same input to the same replacement
 * (docs/design/referenced-identifier-structured-column-sanitization/spec,
 * "Composition").
 *
 * See docs/design/value-generation-primitives/spec, "R1.2 — Deterministic uniqueness".
 */
final class KeyedValueRegistry
{
    /** @var array<string, string> namespace => shape signature */
    private array $signatures = [];

    /** @var array<string, list<array{connection: ?string, table: string, column: string, path: ?string, unique: bool}>> */
    private array $bindings = [];

    /** @var array<string, true> */
    private array $uniqueBound = [];

    /** @var array<string, array<string, true>> namespace => comparison key => true */
    private array $forbidden = [];

    /** @var array<string, true> */
    private array $seeded = [];

    /** @var array<string, array<string, string>> namespace => digest => output */
    private array $assigned = [];

    /** @var array<string, array<string, string>> namespace => comparison key => digest */
    private array $owners = [];

    private ?string $key = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly UniqueColumnInspector $inspector,
    ) {}

    /**
     * @throws InvalidConfigurationException on a missing or short key, or a shape mismatch within a namespace
     */
    public function register(Model $model, Sanitizer $sanitizer): void
    {
        $table = $model->getTable();
        $connection = $model->getConnectionName();

        foreach ($sanitizer->fields() as $column => $definition) {
            if ($definition instanceof JsonPaths) {
                foreach ($definition->definitions() as $path => $inner) {
                    if (! $inner instanceof Keyed) {
                        continue;
                    }

                    $namespace = $this->registerShape($inner);

                    // A path is never unique: no schema index addresses it, so it seeds no originals.
                    $this->bindings[$namespace][] = [
                        'connection' => $connection,
                        'table' => $table,
                        'column' => (string) $column,
                        'path' => (string) $path,
                        'unique' => false,
                    ];
                }

                continue;
            }

            if (! $definition instanceof Keyed) {
                continue;
            }

            $namespace = $this->registerShape($definition);

            $unique = $this->isUniqueMember($table, $connection, (string) $column);

            $this->bindings[$namespace][] = [
                'connection' => $connection,
                'table' => $table,
                'column' => (string) $column,
                'path' => null,
                'unique' => $unique,
            ];

            if ($unique) {
                $this->uniqueBound[$namespace] = true;
                $this->seedOriginals($namespace, $connection, $table, (string) $column);
            }
        }
    }

    public function isUniqueBound(string $namespace): bool
    {
        return isset($this->uniqueBound[$namespace]);
    }

    /**
     * This run's bindings for $namespace, in registration order. A path binding (path non-null) is
     * never unique: no schema index addresses a JSON path, so it seeds no originals and never makes
     * the namespace unique-bound; it joins whatever the namespace is.
     *
     * @return list<array{connection: ?string, table: string, column: string, path: ?string, unique: bool}>
     */
    public function bindings(string $namespace): array
    {
        return $this->bindings[$namespace] ?? [];
    }

    public function assigned(string $namespace, string $digest): ?string
    {
        return $this->assigned[$namespace][$digest] ?? null;
    }

    /** Not owned by another input and not an original of a bound unique column. */
    public function isAvailable(string $namespace, string $comparisonKey): bool
    {
        return ! isset($this->owners[$namespace][$comparisonKey])
            && ! isset($this->forbidden[$namespace][$comparisonKey]);
    }

    public function assign(string $namespace, string $digest, string $output, string $comparisonKey): void
    {
        $this->assigned[$namespace][$digest] = $output;
        $this->owners[$namespace][$comparisonKey] = $digest;
    }

    /** The key bytes, loaded once per run. */
    public function key(): string
    {
        return $this->key ??= KeyedKey::fromConfig();
    }

    /** Clears all run-scoped state, including the cached key. */
    public function reset(): void
    {
        $this->signatures = [];
        $this->bindings = [];
        $this->uniqueBound = [];
        $this->forbidden = [];
        $this->seeded = [];
        $this->assigned = [];
        $this->owners = [];
        $this->key = null;
    }

    public static function comparisonKey(mixed $value): string
    {
        return mb_strtolower((string) $value, 'UTF-8');
    }

    /**
     * Loads the key (so the first Keyed value of a run fails fast on a bad key) and checks the namespace's shape signature.
     *
     * @throws InvalidConfigurationException
     */
    private function registerShape(Keyed $definition): string
    {
        $this->key();

        $namespace = $definition->namespace();
        $signature = $definition->signature();

        if (isset($this->signatures[$namespace]) && $this->signatures[$namespace] !== $signature) {
            throw InvalidConfigurationException::keyedNamespaceShapeMismatch($namespace);
        }

        $this->signatures[$namespace] = $signature;

        return $namespace;
    }

    private function isUniqueMember(string $table, ?string $connection, string $column): bool
    {
        foreach ($this->inspector->uniqueConstraints($table, $connection) as $constraint) {
            if (in_array($column, $constraint, true)) {
                return true;
            }
        }

        return false;
    }

    /** Streams the column's distinct non-null originals into the namespace's forbidden set, once per binding. */
    private function seedOriginals(string $namespace, ?string $connection, string $table, string $column): void
    {
        $seedKey = $namespace."\x1f".($connection ?? '')."\x1f".$table."\x1f".$column;

        if (isset($this->seeded[$seedKey])) {
            return;
        }

        $this->seeded[$seedKey] = true;

        $originals = $this->db->connection($connection)->table($table)->select($column)->distinct()->cursor();

        foreach ($originals as $original) {
            $value = $original->{$column};

            if ($value === null) {
                continue;
            }

            $this->forbidden[$namespace][self::comparisonKey($value)] = true;
        }
    }
}
