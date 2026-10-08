<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Random\Randomizer;
    use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

    class RvDrawShape extends RandomizedValue
    {
        public function generate(mixed $value, Randomizer $random): string
        {
            return implode(',', [$random->getInt(0, 1000000), $random->getInt(0, 1000000)]);
        }

        public function signature(): string
        {
            return 'rv-draw';
        }
    }

    class RvFakeRow extends Model
    {
        protected $table = 'rv_rows';

        protected $guarded = [];
    }
}

namespace {

    use Faker\Factory;
    use Random\Engine\Mt19937;
    use Random\Randomizer;
    use Shahirul22\LaravelPiiSanitizer\Contracts\RandomizedGenerator;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\ValueDefinitionResolver;
    use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

    it('is a ValueGenerator and a RandomizedGenerator', function () {
        $shape = new RvDrawShape;

        expect($shape)->toBeInstanceOf(ValueGenerator::class);
        expect($shape)->toBeInstanceOf(RandomizedGenerator::class);
    });

    it('returns null for a null input without drawing', function () {
        // Faker::seed() seeds the global mt_rand, so the two runs must be
        // sequential: seed, act, read the next draw; then seed again.
        $faker = Factory::create();

        $faker->seed(2026);
        expect((new RvDrawShape)(null, $faker, new RvFakeRow))->toBeNull();
        $afterNull = $faker->numberBetween(0, 2147483647);

        $faker->seed(2026);
        $untouched = $faker->numberBetween(0, 2147483647);

        expect($afterNull)->toBe($untouched);
    });

    it('builds its Randomizer byte-exactly from the Faker stream', function () {
        $faker = Factory::create();

        $faker->seed(2026);
        $actual = (new RvDrawShape)('x', $faker, new RvFakeRow);

        $faker->seed(2026);
        $expected = (new RvDrawShape)->generate(
            'x',
            new Randomizer(new Mt19937($faker->numberBetween(0, 2147483647)))
        );

        expect($actual)->toBe($expected);
    });

    it('exposes the bridge Randomizer byte-exactly', function () {
        $faker = Factory::create();

        $faker->seed(2026);
        $actual = (new RvDrawShape)->generate('x', RandomizedValue::randomizer($faker));

        $faker->seed(2026);
        $expected = (new RvDrawShape)->generate(
            'x',
            new Randomizer(new Mt19937($faker->numberBetween(0, 2147483647)))
        );

        expect($actual)->toBe($expected);
    });

    it('is reproducible under a seeded Faker', function () {
        $outputs = [];

        foreach ([7, 7] as $seed) {
            $faker = Factory::create();
            $faker->seed($seed);
            $shape = new RvDrawShape;
            $outputs[] = [$shape('x', $faker, new RvFakeRow), $shape('x', $faker, new RvFakeRow)];
        }

        expect($outputs[0])->toBe($outputs[1]);
    });

    it('resolves through ValueDefinitionResolver as an ordinary value-definition', function () {
        $result = (new ValueDefinitionResolver)->resolve(new RvDrawShape, 'x', Factory::create(), new RvFakeRow);

        expect($result)->toMatch('/^\d+,\d+$/');
    });
}
