<?php

namespace Shahirul22\LaravelPiiSanitizer\Values;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Shahirul22\LaravelPiiSanitizer\Contracts\RandomizedGenerator;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UniquenessExhaustedException;

/**
 * Resolves a Keyed definition for one input value.
 *
 * A pure namespace is stateless: the output is the probe-0 candidate. A
 * unique-bound namespace resolves through KeyedValueRegistry: an input seen
 * before returns its memoised output (so every row and every mirror column
 * with the same input agrees within a run); a new input takes the first
 * probe 0..MAX_PROBES-1 whose candidate is not owned by another input, is
 * not an original of a bound unique column, and differs from the input
 * itself. That is how uniqueness holds deterministically without v1's
 * random retry loop.
 *
 * The derivation is byte-exact and versioned ("pii-keyed/v1"); any change to
 * it changes every keyed output and is a semver-major change.
 *
 * Every parameter that carries the key bytes or a raw input value is
 * #[\SensitiveParameter], so neither can appear in a stack trace's arguments
 * (the key is equivalent to the PII it protects).
 */
final class KeyedResolver
{
    public const MAX_PROBES = 100;

    public function __construct(
        private readonly KeyedValueRegistry $registry,
    ) {}

    /**
     * @throws InvalidReplacementValueException for an unsupported input type
     * @throws UniquenessExhaustedException when no probe yields an available candidate
     */
    public function resolve(Keyed $definition, #[\SensitiveParameter] mixed $input): ?string
    {
        $canonical = self::canonical($input);

        if ($canonical === null) {
            return null;
        }

        $namespace = $definition->namespace();
        $shape = $definition->shape();
        $key = $this->registry->key();

        if (! $this->registry->isUniqueBound($namespace)) {
            return self::candidate($key, $namespace, $canonical, 0, $shape, $canonical);
        }

        $digest = self::digest($key, $namespace, $canonical);
        $assigned = $this->registry->assigned($namespace, $digest);

        if ($assigned !== null) {
            return $assigned;
        }

        $own = KeyedValueRegistry::comparisonKey($canonical);

        for ($probe = 0; $probe < self::MAX_PROBES; $probe++) {
            $candidate = self::candidate($key, $namespace, $canonical, $probe, $shape, $canonical);
            $comparison = KeyedValueRegistry::comparisonKey($candidate);

            if ($comparison === $own || ! $this->registry->isAvailable($namespace, $comparison)) {
                continue;
            }

            $this->registry->assign($namespace, $digest, $candidate, $comparison);

            return $candidate;
        }

        throw UniquenessExhaustedException::keyedProbesExhausted($namespace, self::MAX_PROBES);
    }

    /**
     * The canonical string form of an input, or null for null.
     *
     * @throws InvalidReplacementValueException for a float, array or other object
     */
    public static function canonical(#[\SensitiveParameter] mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_int($value) => (string) $value,
            is_string($value) => $value,
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \BackedEnum => self::canonical($value->value),
            $value instanceof \Stringable => (string) $value,
            default => throw InvalidReplacementValueException::unsupportedInput('keyed', get_debug_type($value)),
        };
    }

    /** The 32 raw seed bytes for one (namespace, input, probe). */
    public static function seed(#[\SensitiveParameter] string $key, string $namespace, #[\SensitiveParameter] string $canonical, int $probe): string
    {
        $message = 'pii-keyed/v1'."\x1f".$namespace."\x1f".$canonical."\x1f".(string) $probe;

        return hash_hmac('sha256', $message, $key, true);
    }

    /** The shape's output for one probe, drawn from the HMAC-seeded Randomizer. */
    public static function candidate(#[\SensitiveParameter] string $key, string $namespace, #[\SensitiveParameter] string $canonical, int $probe, RandomizedGenerator $shape, #[\SensitiveParameter] mixed $value): string
    {
        $random = new Randomizer(
            new Xoshiro256StarStar(self::seed($key, $namespace, $canonical, $probe))
        );

        return $shape->generate($value, $random);
    }

    /** A 16-byte identity for the input, in a separate HMAC domain, so run memory holds no raw PII. */
    private static function digest(#[\SensitiveParameter] string $key, string $namespace, #[\SensitiveParameter] string $canonical): string
    {
        $message = 'pii-keyed/v1/id'."\x1f".$namespace."\x1f".$canonical;

        return substr(hash_hmac('sha256', $message, $key, true), 0, 16);
    }
}
