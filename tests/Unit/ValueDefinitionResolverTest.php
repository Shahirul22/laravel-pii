<?php

namespace {
    use Faker\Generator;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;

    class VdrFakeRow extends Model
    {
        protected $table = 'vdr_fake_rows';

        protected $guarded = [];
    }

    class VdrUppercaseGenerator implements ValueGenerator
    {
        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            return strtoupper((string) $value);
        }
    }

    class VdrNotAValueGenerator
    {
        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            return 'should-never-be-called';
        }
    }
}

namespace {

    use Faker\Factory;
    use Faker\Generator;
    use Shahirul22\LaravelPiiSanitizer\ValueDefinitionResolver;

    it('returns a static scalar value unchanged', function () {
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $row = new VdrFakeRow;

        expect($resolver->resolve('literal@example.com', 'old', $faker, $row))->toBe('literal@example.com');
        expect($resolver->resolve(42, 'old', $faker, $row))->toBe(42);
        expect($resolver->resolve(true, 'old', $faker, $row))->toBe(true);
        expect($resolver->resolve(['a' => 1], 'old', $faker, $row))->toBe(['a' => 1]);
        expect($resolver->resolve(null, 'old', $faker, $row))->toBeNull();
    });

    it('invokes a closure with (value, faker, row) and returns its result', function () {
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $row = new VdrFakeRow;

        $result = $resolver->resolve(fn ($value, $faker, $row) => $value.'!', 'old', $faker, $row);
        expect($result)->toBe('old!');

        $rowResult = $resolver->resolve(fn ($value, $faker, $row) => $row::class, 'old', $faker, $row);
        expect($rowResult)->toBe(VdrFakeRow::class);

        $fakerResult = $resolver->resolve(fn ($value, $faker, $row) => $faker instanceof Generator, 'old', $faker, $row);
        expect($fakerResult)->toBeTrue();
    });

    it('resolves an invokable ValueGenerator class-string via the container', function () {
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $row = new VdrFakeRow;

        $result = $resolver->resolve(VdrUppercaseGenerator::class, 'abc', $faker, $row);
        expect($result)->toBe('ABC');
    });

    it('resolves a Faker method-name shorthand string against the faker', function () {
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $row = new VdrFakeRow;

        $result = $resolver->resolve('safeEmail', 'old', $faker, $row);
        expect($result)->toBeString();
        expect(str_contains($result, '@'))->toBeTrue();
    });

    it('treats a plain string that is neither a class nor a faker method as a static value', function () {
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $row = new VdrFakeRow;

        $result = $resolver->resolve('not-a-faker-method-xyz', 'old', $faker, $row);
        expect($result)->toBe('not-a-faker-method-xyz');
    });

    it('prefers the invokable-class branch over the faker-shorthand branch', function () {
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $row = new VdrFakeRow;

        $result = $resolver->resolve(VdrUppercaseGenerator::class, 'xyz', $faker, $row);
        expect($result)->toBe('XYZ');
    });

    it('always treats a string that collides with a Faker method name as the shorthand, never as a static literal', function () {
        // 'name' is both a plausible literal static value AND a real Faker
        // method name — there is no way to force the literal interpretation
        // once a string matches a Faker formatter, since Faker-shorthand
        // dispatch is unconditional (checked before the static-value
        // fallback). This test locks in that resolution order explicitly:
        // a developer writing 'name' => 'name' in fields() gets Faker's
        // generated name, never the literal string "name".
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $faker->seed(2026);
        $row = new VdrFakeRow;

        $result = $resolver->resolve('name', 'old', $faker, $row);

        expect($result)->not->toBe('name');
        expect($result)->toBeString();
    });

    it('does not treat an existing class that does not implement ValueGenerator as a generator', function () {
        // VdrNotAValueGenerator exists and is even invokable, but doesn't
        // implement ValueGenerator — is_subclass_of()'s negative case.
        // Since it's also not a Faker method name, it must fall through to
        // the static-value branch (the class-string itself, unchanged),
        // never be resolved via the container and invoked.
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $row = new VdrFakeRow;

        $result = $resolver->resolve(VdrNotAValueGenerator::class, 'old', $faker, $row);

        expect($result)->toBe(VdrNotAValueGenerator::class);
        expect($result)->not->toBe('should-never-be-called');
    });

    it('resolves every other Faker-colliding literal-looking string as the Faker call too, consistently', function () {
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $row = new VdrFakeRow;

        // 'city' and 'company' are both realistic static-value-looking
        // strings a developer might write, and both are real Faker
        // formatter names — same collision as 'name' above, confirming the
        // behavior is consistent across formatters, not a one-off.
        expect($resolver->resolve('city', 'old', $faker, $row))->not->toBe('city');
        expect($resolver->resolve('company', 'old', $faker, $row))->not->toBe('company');
    });
}
