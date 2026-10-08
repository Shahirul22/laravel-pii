<?php

namespace Shahirul22\LaravelPiiSanitizer\Values;

use Shahirul22\LaravelPiiSanitizer\Values\Json\JsonPaths;

/**
 * Path-addressed sanitization of a JSON or array-cast column.
 *
 *     'preferences' => Json::paths([
 *         'contact->phone' => Format::keepLast(4),
 *         'contact->email' => Keyed::using('email', Format::keepEmailDomain()),
 *         'emergency->*->name' => 'name',
 *     ]),
 *
 * The column is the fields() key; every path is relative to that column's
 * document. Only the declared paths are rewritten; every other key, the
 * nesting and the value types are left as they were.
 * Sanitizer::fields() is evaluated per row, so construction must stay trivial.
 *
 * See docs/design/referenced-identifier-structured-column-sanitization/spec, "R2 — JSON path map".
 */
final class Json
{
    private function __construct() {}

    /**
     * @param  array<array-key, mixed>  $paths  path => value-definition
     *
     * @throws \InvalidArgumentException
     */
    public static function paths(array $paths): JsonPaths
    {
        return new JsonPaths($paths);
    }
}
