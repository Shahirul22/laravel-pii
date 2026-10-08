<?php

namespace {
    use Illuminate\Database\Eloquent\Model;

    class MlyProviderRow extends Model
    {
        protected $table = 'mly_provider_rows';

        protected $guarded = [];
    }
}

namespace {

    use Faker\Factory;
    use Faker\Generator;
    use Faker\Provider\en_US\Person;
    use Shahirul22\LaravelPiiSanitizer\PiiSanitizerServiceProvider;
    use Shahirul22\LaravelPiiSanitizer\Tests\Support\MalaysiaFormats;
    use Shahirul22\LaravelPiiSanitizer\ValueDefinitionResolver;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\MalaysiaProvider;

    $mlyCount = fn (Generator $faker): int => count(array_filter($faker->getProviders(), fn ($p) => $p instanceof MalaysiaProvider));

    it('extends the container Faker once without changing the locale', function () use ($mlyCount) {
        $faker = app(Generator::class);

        expect(config('app.faker_locale'))->toBe('en_US');
        expect($mlyCount($faker))->toBe(1);
        expect(array_filter($faker->getProviders(), fn ($p) => $p instanceof Person))->not->toBeEmpty();
    });

    it('generates valid values through each shorthand', function () {
        $faker = app(Generator::class);

        for ($i = 0; $i < 200; $i++) {
            expect(MalaysiaFormats::nricIsValid($faker->malaysiaNric()))->toBeTrue();
            expect(MalaysiaFormats::sstIsValid($faker->malaysiaSst()))->toBeTrue();
            expect(in_array($faker->malaysiaState(), MalaysiaFormats::STATES, true))->toBeTrue();
            expect(MalaysiaFormats::bankAccountIsValid($faker->malaysiaBankAccount()))->toBeTrue();
        }

        expect(MalaysiaFormats::nricIsValid($faker->malaysiaNric(true, 'female'), true, 'female'))->toBeTrue();
        expect($faker->malaysiaBankAccount('BSN'))->toMatch('/^[1-9]\d{15}$/');
    });

    it('resolves the shorthand through ValueDefinitionResolver and classifies it non-static', function (string $name, Closure $oracle) {
        $faker = app(Generator::class);
        $resolver = new ValueDefinitionResolver;

        expect($resolver->isStatic($name, $faker))->toBeFalse();

        $value = $resolver->resolve($name, null, $faker, new MlyProviderRow);

        expect($value)->toBeString()->not->toBe($name);
        expect($oracle($value))->toBeTrue();
    })->with([
        'malaysiaNric' => ['malaysiaNric', fn ($v) => MalaysiaFormats::nricIsValid($v)],
        'malaysiaSst' => ['malaysiaSst', fn ($v) => MalaysiaFormats::sstIsValid($v)],
        'malaysiaState' => ['malaysiaState', fn ($v) => in_array($v, MalaysiaFormats::STATES, true)],
        'malaysiaBankAccount' => ['malaysiaBankAccount', fn ($v) => MalaysiaFormats::bankAccountIsValid($v)],
    ]);

    it('is reproducible under a seeded container Faker', function () {
        $faker = app(Generator::class);
        $draw = fn () => [$faker->malaysiaNric(), $faker->malaysiaSst(), $faker->malaysiaState(), $faker->malaysiaBankAccount()];

        $faker->seed(42);
        $first = $draw();
        $faker->seed(42);

        expect($draw())->toBe($first);
    });

    it('does not register twice when Faker is resolved again', function () use ($mlyCount) {
        $first = app(Generator::class);
        app()->forgetInstance(Generator::class);
        $again = app(Generator::class);

        expect($again)->toBe($first);
        expect($mlyCount($again))->toBe(1);
    });

    it('extends a Faker that was resolved before the provider registered', function () use ($mlyCount) {
        app()->instance(Generator::class, $fresh = Factory::create());

        (new PiiSanitizerServiceProvider(app()))->register();
        (new PiiSanitizerServiceProvider(app()))->register();

        expect($mlyCount($fresh))->toBe(1);
        expect(in_array($fresh->malaysiaState(), MalaysiaFormats::STATES, true))->toBeTrue();
    });

    it('is a container feature only', function () {
        expect((new ValueDefinitionResolver)->isStatic('malaysiaNric', Factory::create()))->toBeTrue();
    });
}
