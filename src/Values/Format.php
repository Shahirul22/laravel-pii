<?php

namespace Shahirul22\LaravelPiiSanitizer\Values;

use Shahirul22\LaravelPiiSanitizer\Values\Format\KeepEmailDomain;
use Shahirul22\LaravelPiiSanitizer\Values\Format\KeepLast;
use Shahirul22\LaravelPiiSanitizer\Values\Format\KeepLength;
use Shahirul22\LaravelPiiSanitizer\Values\Format\KeepPrefix;

/**
 * Format-preserving value definitions for shape-sensitive columns.
 *
 *     'phone' => Format::keepLast(4),
 *     'email' => Keyed::using('email', Format::keepEmailDomain()),
 *
 * Used bare, a helper is a random-mode value definition and goes through v1's
 * retry loop on unique columns. Wrapped in Keyed it is deterministic. A helper
 * is also callable inside a closure: Format::keepLast(4)($value, $faker, $row).
 * Sanitizer::fields() is evaluated per row, so construction stays trivial.
 *
 * See docs/design/value-generation-primitives/spec, "R3 - Format-preserving helpers".
 */
final class Format
{
    private function __construct() {}

    /** Replace letters and digits, keeping length and separators; an optional one-character mask replaces them with that character. */
    public static function keepLength(?string $mask = null): KeepLength
    {
        return new KeepLength($mask);
    }

    /** Keep the first $count code points, replace the rest. */
    public static function keepPrefix(int $count): KeepPrefix
    {
        return new KeepPrefix($count);
    }

    /** Keep the last $count code points, replace the rest. */
    public static function keepLast(int $count): KeepLast
    {
        return new KeepLast($count);
    }

    /** Replace the local part of an email address, keep the domain. */
    public static function keepEmailDomain(): KeepEmailDomain
    {
        return new KeepEmailDomain;
    }
}
