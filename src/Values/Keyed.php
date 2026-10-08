<?php

namespace Shahirul22\LaravelPiiSanitizer\Values;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\RandomizedGenerator;
use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
use Shahirul22\LaravelPiiSanitizer\Values\Format\Pattern;

/**
 * Deterministic keyed value-definition: the same input value maps to the
 * same replacement across rows, columns, tables and runs, as long as the
 * configured key (PII_SANITIZER_KEY), the namespace and the shape are the
 * same. The column name is never part of the derivation.
 *
 * Usage in Sanitizer::fields():
 *   'nric'     => Keyed::using('nric', Malaysia::nric()),
 *   'staff_id' => Keyed::pattern('staff', 'S#####'),
 *
 * Determinism, exactly: the output for input x is candidate(x, i*), where i*
 * is the smallest probe index that passes collision resolution. On a pure
 * namespace (no unique-constrained column) i* is always 0. On a unique-bound
 * namespace i* > 0 only on a real collision, and which of two colliding
 * inputs keeps probe 0 depends on first-seen order, so a --model-filtered run
 * or changed data can move a value that collided.
 *
 * Residual reversibility: with the key, the algorithm and the namespace,
 * low-entropy inputs can be recovered by enumeration, so the key is
 * equivalent to the PII it protects. Equal inputs give equal outputs by
 * design. See docs/design/value-generation-primitives/spec, "Residual
 * reversibility and correlation".
 *
 * fields() is evaluated per row, so construction must stay trivial.
 */
final class Keyed implements ValueGenerator
{
    private const NAMESPACE_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    /**
     * @throws \InvalidArgumentException when the namespace does not match /^[A-Za-z0-9_.:-]{1,64}$/
     */
    private function __construct(
        private readonly string $namespace,
        private readonly RandomizedGenerator $shape,
    ) {
        if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw new \InvalidArgumentException('[laravel-pii-sanitizer] A Keyed namespace must match '.self::NAMESPACE_PATTERN.'.');
        }
    }

    public static function using(string $namespace, RandomizedGenerator $shape): self
    {
        return new self($namespace, $shape);
    }

    public static function pattern(string $namespace, string $pattern): self
    {
        return self::using($namespace, new Pattern($pattern));
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function shape(): RandomizedGenerator
    {
        return $this->shape;
    }

    /** Shape class and parameters; every Keyed in one namespace must agree. */
    public function signature(): string
    {
        return $this->shape::class."\x1f".$this->shape->signature();
    }

    public function __invoke(mixed $value, Generator $faker, Model $row): mixed
    {
        return app(KeyedResolver::class)->resolve($this, $value);
    }
}
