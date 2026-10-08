<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    /** Not a model, and the container cannot build it: its constructor needs a value. */
    class NmcNeedsArgument
    {
        public function __construct(public string $required) {}
    }

    /** Not a model, and abstract, so the container cannot build it either. */
    abstract class NmcAbstractThing {}

    class NmcUser extends Model
    {
        protected $table = 'nmc_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class NmcUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['name' => 'name'];
        }

        public function categorical(): array
        {
            return ['name'];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Contracts\DataQualityGuardContract;
    use Shahirul22\LaravelPiiSanitizer\Contracts\SchemaGuardContract;
    use Shahirul22\LaravelPiiSanitizer\DataQualityGuard;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\SanitizerResolver;
    use Shahirul22\LaravelPiiSanitizer\SchemaGuard;

    // BUG-5 (round 2): an existing class that is not a model, or not a
    // sanitizer, used to be built with app() before the type check, so a
    // constructor the container cannot satisfy raised its raw
    // BindingResolutionException instead of the package's named error.

    beforeEach(function () {
        Schema::create('nmc_users', function ($table) {
            $table->id();
            $table->string('name');
        });
    });

    it('names an existing non-model class in pii.models or --model whose constructor needs a value', function (string $class, bool $viaOption) {
        if (! $viaOption) {
            config()->set('pii.models', [$class]);
        }

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(models: $viaOption ? [$class] : null, chunkSize: 10)))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::invalidModelClass($class)->getMessage());
    })->with([
        'required constructor parameter, pii.models' => [NmcNeedsArgument::class, false],
        'required constructor parameter, --model' => [NmcNeedsArgument::class, true],
        'abstract class, --model' => [NmcAbstractThing::class, true],
    ]);

    it('names an existing non-model class given to the schema and data-quality guards as a class-string', function (string $class) {
        expect(fn () => app(SchemaGuard::class)->assertSafe(new NmcUserSanitizer, $class))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::invalidModelClass($class)->getMessage());

        expect(fn () => app(DataQualityGuard::class)->assertValid(new NmcUserSanitizer, $class))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::invalidModelClass($class)->getMessage());
    })->with([[NmcNeedsArgument::class], [NmcAbstractThing::class]]);

    it('names an existing non-sanitizer class in pii.sanitizers or pii.tables whose constructor needs a value', function (string $class) {
        config()->set('pii.sanitizers', [NmcUser::class => $class]);
        config()->set('pii.tables', ['nmc_users' => $class]);

        $resolver = new SanitizerResolver(app(SchemaGuardContract::class), app(DataQualityGuardContract::class));

        expect(fn () => $resolver->resolve(NmcUser::class))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::invalidSanitizerClass($class, 'pii.sanitizers')->getMessage());
        expect(fn () => $resolver->resolveTable('nmc_users'))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::invalidSanitizerClass($class, 'pii.tables')->getMessage());
    })->with([[NmcNeedsArgument::class], [NmcAbstractThing::class]]);

    it('still resolves a model and a sanitizer bound in the container under a name that is not a class', function () {
        app()->bind('nmc.model', fn () => new NmcUser);
        app()->bind('nmc.sanitizer', fn () => new NmcUserSanitizer);

        config()->set('pii.sanitizers', [NmcUser::class => 'nmc.sanitizer']);

        $resolver = new SanitizerResolver(app(SchemaGuardContract::class), app(DataQualityGuardContract::class));

        expect($resolver->resolve(NmcUser::class))->toBeInstanceOf(NmcUserSanitizer::class);

        app(SchemaGuard::class)->assertSafe(new NmcUserSanitizer, 'nmc.model');
        app(DataQualityGuard::class)->assertValid(new NmcUserSanitizer, 'nmc.model');
    });
}
