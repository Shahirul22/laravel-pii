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
}
