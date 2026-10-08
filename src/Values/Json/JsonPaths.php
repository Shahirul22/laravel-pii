<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Json;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
use Shahirul22\LaravelPiiSanitizer\ValueDefinitionResolver;

/**
 * Rewrites only the declared paths inside one JSON or array-cast column value.
 * Built through Json::paths(); see docs/design/referenced-identifier-structured-column-sanitization/spec,
 * "R2 — JSON path map".
 *
 * Path grammar: segments joined by "->"; a segment is "*" (every array element
 * or object member, in document order) or a key (an exact object member, or a
 * canonical non-negative integer array index). No two paths may overlap.
 *
 * The single absent/null rule, byte-exact:
 *
 * A declared path is skipped for a row when its target is absent or null: the column value is NULL or the empty string, the value at a segment that must descend is not a JSON object or array, a key or index on the way is missing, or the value found is JSON null. A skipped path's definition is not called, nothing is written for it, no structure is created and no error is raised. Every other declared path in the same row is still applied. A wildcard segment applies this rule to each matched element on its own; a wildcard over an empty array or object matches nothing.
 *
 * A string column value is decoded, patched and re-encoded; an array column
 * value (an array-cast column) is patched and returned as an array. When no
 * path was rewritten the received value is returned unchanged.
 */
final class JsonPaths implements ValueGenerator
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Path string => parsed segments, process-wide memo (fields() is evaluated per row).
     *
     * @var array<string, list<string>>
     */
    private static array $segmentCache = [];

    /** @var array<string, mixed> path => definition, declaration order */
    private readonly array $definitions;

    /** @var array<string, list<string>> path => segments, declaration order */
    private readonly array $segments;

    /**
     * @param  array<array-key, mixed>  $paths  path => value-definition
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(array $paths)
    {
        if ($paths === []) {
            throw new \InvalidArgumentException('[laravel-pii-sanitizer] Json::paths() needs at least one path.');
        }

        $definitions = [];
        $segments = [];

        foreach ($paths as $key => $definition) {
            // PHP coerces a '5' key to int; 5 is a valid canonical-index path.
            $path = (string) $key;

            $segments[$path] = self::parse($path);

            if ($definition instanceof self) {
                throw new \InvalidArgumentException("[laravel-pii-sanitizer] Json::paths() path \"{$path}\" is itself a Json::paths() definition; nesting is not supported.");
            }

            $definitions[$path] = $definition;
        }

        $declared = array_keys($segments);

        foreach ($declared as $i => $a) {
            foreach (array_slice($declared, $i + 1) as $b) {
                if (self::overlaps($segments[$a], $segments[$b])) {
                    throw new \InvalidArgumentException("[laravel-pii-sanitizer] Json::paths() paths \"{$a}\" and \"{$b}\" overlap; each JSON location may be declared once.");
                }
            }
        }

        $this->definitions = $definitions;
        $this->segments = $segments;
    }

    /**
     * The declared definitions, path => definition, in declaration order. A canonical-index path such as '0'
     * comes back as an int key (PHP array-key coercion), so readers must cast the key with (string).
     *
     * @return array<string, mixed>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * @throws InvalidReplacementValueException
     */
    public function __invoke(mixed $value, Generator $faker, Model $row): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (is_string($value)) {
            try {
                $tree = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw InvalidReplacementValueException::invalidJsonDocument();
            }

            if (! $this->applyAll($tree, $faker, $row)) {
                return $value;
            }

            try {
                return json_encode($tree, self::ENCODE_FLAGS);
            } catch (\JsonException) {
                throw InvalidReplacementValueException::invalidJsonDocument();
            }
        }

        if (is_array($value)) {
            $tree = $value;

            return $this->applyAll($tree, $faker, $row) ? $tree : $value;
        }

        throw InvalidReplacementValueException::unsupportedJsonCarrier(get_debug_type($value));
    }

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    private static function parse(string $path): array
    {
        if (isset(self::$segmentCache[$path])) {
            return self::$segmentCache[$path];
        }

        $segments = explode('->', $path);

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException("[laravel-pii-sanitizer] Json::paths() path \"{$path}\" has an empty segment.");
            }

            if (preg_match('/\[\d*\]/', $segment) === 1) {
                throw new \InvalidArgumentException("[laravel-pii-sanitizer] Json::paths() path \"{$path}\" uses a bracket index in segment \"{$segment}\"; write ->0 or ->* instead.");
            }
        }

        return self::$segmentCache[$path] = $segments;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function overlaps(array $a, array $b): bool
    {
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            if ($a[$i] !== $b[$i] && $a[$i] !== '*' && $b[$i] !== '*') {
                return false;
            }
        }

        return true;
    }

    /**
     * Applies every declared path in declaration order; true when at least one was rewritten.
     */
    private function applyAll(mixed &$tree, Generator $faker, Model $row): bool
    {
        $resolver = app(ValueDefinitionResolver::class);
        $rewritten = false;

        foreach ($this->segments as $path => $segments) {
            // The call comes first so a later path still runs after an earlier one rewrote.
            $rewritten = $this->applyPath($tree, $segments, 0, (string) $path, $this->definitions[$path], $resolver, $faker, $row) || $rewritten;
        }

        return $rewritten;
    }

    /**
     * @param  list<string>  $segments
     */
    private function applyPath(mixed &$node, array $segments, int $depth, string $path, mixed $definition, ValueDefinitionResolver $resolver, Generator $faker, Model $row): bool
    {
        $segment = $segments[$depth];
        $last = $depth === count($segments) - 1;
        $hit = false;

        if (is_array($node)) {
            $keys = $segment === '*'
                ? array_keys($node)
                : (array_key_exists($segment, $node) ? [$segment] : []);

            foreach ($keys as $key) {
                if ($last) {
                    if ($node[$key] === null) {
                        continue;
                    }

                    $node[$key] = $this->replacement($resolver, $definition, $node[$key], $path, $faker, $row);
                    $hit = true;

                    continue;
                }

                $hit = $this->applyPath($node[$key], $segments, $depth + 1, $path, $definition, $resolver, $faker, $row) || $hit;
            }

            return $hit;
        }

        if ($node instanceof \stdClass) {
            $members = get_object_vars($node);
            $keys = $segment === '*'
                ? array_keys($members)
                : (array_key_exists($segment, $members) ? [$segment] : []);

            foreach ($keys as $key) {
                $name = (string) $key;
                $child = $node->{$name};

                if ($last) {
                    if ($child === null) {
                        continue;
                    }

                    $node->{$name} = $this->replacement($resolver, $definition, $child, $path, $faker, $row);
                    $hit = true;

                    continue;
                }

                if ($this->applyPath($child, $segments, $depth + 1, $path, $definition, $resolver, $faker, $row)) {
                    // An array child is a copy here, so assign it back.
                    $node->{$name} = $child;
                    $hit = true;
                }
            }
        }

        return $hit;
    }

    /**
     * @throws InvalidReplacementValueException
     */
    private function replacement(ValueDefinitionResolver $resolver, mixed $definition, mixed $leaf, string $path, Generator $faker, Model $row): mixed
    {
        try {
            return self::writable($resolver->resolve($definition, $leaf, $faker, $row));
        } catch (InvalidReplacementValueException $e) {
            throw InvalidReplacementValueException::inPath($path, $e);
        }
    }

    /**
     * The debug type of the first value that cannot be written into JSON, depth-first, or null when $value is writable:
     * null, a bool, an int, a string, a finite float, an enum, or an array of those (recursively).
     * The boot-time twin of writable(); SchemaGuard uses it for static path definitions.
     */
    public static function unwritableType(mixed $value): ?string
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value) || $value instanceof \UnitEnum) {
            return null;
        }

        if (is_float($value)) {
            return is_finite($value) ? null : 'float';
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $type = self::unwritableType($item);

                if ($type !== null) {
                    return $type;
                }
            }

            return null;
        }

        return get_debug_type($value);
    }

    /**
     * @throws InvalidReplacementValueException
     */
    private static function writable(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_array($value)) {
            return array_map(self::writable(...), $value);
        }

        throw InvalidReplacementValueException::unsupportedPathValue(get_debug_type($value));
    }
}
