<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Format;

use Random\Randomizer;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
use Shahirul22\LaravelPiiSanitizer\Values\KeyedResolver;
use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

/**
 * Shared character-class rule for the format-preserving helpers.
 *
 * The input is canonicalised the same way Keyed does (int, string, bool,
 * backed enum or Stringable), validated as UTF-8 and split into code points;
 * length is always counted in code points. In the replaced portion a digit
 * (\p{N}) becomes a random ASCII digit, an uppercase letter (\p{Lu}) a random
 * A-Z and any other letter (\p{L}) a random a-z, so non-ASCII letters are
 * replaced and never kept. Everything else (separators, punctuation,
 * whitespace, symbols) is preserved.
 *
 * Draw order is part of the keyed derivation contract: exactly one
 * Randomizer::getInt() per replaced letter or digit, left to right over the
 * replaced portion only; preserved code points and separators draw nothing.
 * Changing it changes every keyed output, so do not.
 *
 * Wrapping a helper in Keyed makes it deterministic but does not hide what it
 * preserves: the kept portion is still the original's.
 *
 * See docs/design/value-generation-primitives/spec, "R3 - Format-preserving helpers".
 */
abstract class FormatPreservingValue extends RandomizedValue
{
    public const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    /**
     * @throws InvalidReplacementValueException for an unsupported input type or invalid UTF-8
     * @throws \LogicException when called with null (the bridge and KeyedResolver never do)
     */
    final public function generate(#[\SensitiveParameter] mixed $value, Randomizer $random): string
    {
        if ($value === null) {
            throw new \LogicException('[laravel-pii-sanitizer] generate() is never called with null; null is handled by the value-definition bridge.');
        }

        try {
            $canonical = KeyedResolver::canonical($value);
        } catch (InvalidReplacementValueException) {
            throw InvalidReplacementValueException::unsupportedInput($this->signature(), get_debug_type($value));
        }

        if ($canonical === null || ! mb_check_encoding($canonical, 'UTF-8')) {
            throw InvalidReplacementValueException::unsupportedInput($this->signature(), 'invalid-utf8');
        }

        return $this->transform(mb_str_split($canonical, 1, 'UTF-8'), $random);
    }

    /**
     * @param  list<string>  $characters  code points of the canonical input
     */
    abstract protected function transform(array $characters, Randomizer $random): string;

    /**
     * Replace every letter and digit by class, keeping all other characters.
     *
     * @param  list<string>  $characters
     */
    final protected static function replace(array $characters, Randomizer $random): string
    {
        $output = '';

        foreach ($characters as $character) {
            if (preg_match('/^\p{N}$/u', $character) === 1) {
                $output .= (string) $random->getInt(0, 9);
            } elseif (preg_match('/^\p{Lu}$/u', $character) === 1) {
                $output .= Pattern::ALPHA[$random->getInt(0, 25)];
            } elseif (preg_match('/^\p{L}$/u', $character) === 1) {
                $output .= self::LOWER[$random->getInt(0, 25)];
            } else {
                $output .= $character;
            }
        }

        return $output;
    }

    final protected static function isReplaceable(string $character): bool
    {
        return preg_match('/^[\p{N}\p{L}]$/u', $character) === 1;
    }
}
