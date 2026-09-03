<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Contracts\DataQualityGuardContract;
    use Shahirul22\LaravelPiiSanitizer\Contracts\SchemaGuardContract;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class ResolverFakeUser extends Model
    {
        protected $table = 'resolver_fake_users';
    }

    class ResolverFakeUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [];
        }
    }

    class ResolverOverrideSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [];
        }
    }

    class ResolverNotASanitizer
    {
        //
    }

    class ResolverNullSchemaGuard implements SchemaGuardContract
    {
        public function assertSafe(Sanitizer $sanitizer, Model|string $model): void {}
    }

    class ResolverRecordingSchemaGuard implements SchemaGuardContract
    {
        public ?Sanitizer $sanitizer = null;

        public Model|string|null $model = null;

        public function assertSafe(Sanitizer $sanitizer, Model|string $model): void
        {
            $this->sanitizer = $sanitizer;
            $this->model = $model;
        }
    }

    class ResolverNullDataQualityGuard implements DataQualityGuardContract
    {
        public function assertValid(Sanitizer $sanitizer, Model|string $model): void {}
    }

    class ResolverRecordingDataQualityGuard implements DataQualityGuardContract
    {
        public ?Sanitizer $sanitizer = null;

        public Model|string|null $model = null;

        public function assertValid(Sanitizer $sanitizer, Model|string $model): void
        {
            $this->sanitizer = $sanitizer;
            $this->model = $model;
        }
    }
}

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    class ResolverConventionUser extends Model
    {
        protected $table = 'resolver_convention_users';
    }
}

namespace App\Sanitizers {
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class ResolverConventionUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [];
        }
    }
}

namespace {

    use App\Models\ResolverConventionUser;
    use App\Sanitizers\ResolverConventionUserSanitizer;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\SanitizerResolver;

    it('returns null when neither config nor convention resolves', function () {
        config()->set('pii.sanitizers', []);

        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, new ResolverNullDataQualityGuard);

        expect($resolver->resolve(ResolverFakeUser::class))->toBeNull();
    });

    it('resolves by convention when a {Model}Sanitizer exists and no config entry', function () {
        config()->set('pii.sanitizers', []);

        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, new ResolverNullDataQualityGuard);

        expect($resolver->resolve(ResolverConventionUser::class))
            ->toBeInstanceOf(ResolverConventionUserSanitizer::class);
    });

    it('resolves a config override even when no conventional class exists', function () {
        config()->set('pii.sanitizers', [
            ResolverFakeUser::class => ResolverOverrideSanitizer::class,
        ]);

        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, new ResolverNullDataQualityGuard);

        expect($resolver->resolve(ResolverFakeUser::class))
            ->toBeInstanceOf(ResolverOverrideSanitizer::class);
    });

    it('lets a config override take precedence when both a config entry and a conventional class exist', function () {
        config()->set('pii.sanitizers', [
            ResolverConventionUser::class => ResolverOverrideSanitizer::class,
        ]);

        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, new ResolverNullDataQualityGuard);

        $result = $resolver->resolve(ResolverConventionUser::class);

        expect($result)->toBeInstanceOf(ResolverOverrideSanitizer::class);
        expect($result)->not->toBeInstanceOf(ResolverConventionUserSanitizer::class);
    });

    it('accepts a model instance as well as a class-string', function () {
        config()->set('pii.sanitizers', []);

        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, new ResolverNullDataQualityGuard);

        $fromInstance = $resolver->resolve(new ResolverConventionUser);
        $fromClassString = $resolver->resolve(ResolverConventionUser::class);

        expect($fromInstance)->toBeInstanceOf(ResolverConventionUserSanitizer::class);
        expect($fromClassString)->toBeInstanceOf(ResolverConventionUserSanitizer::class);
    });

    it('passes the resolved sanitizer and the model through the schema guard', function () {
        config()->set('pii.sanitizers', []);

        $guard = new ResolverRecordingSchemaGuard;
        $resolver = new SanitizerResolver($guard, new ResolverNullDataQualityGuard);

        $result = $resolver->resolve(ResolverConventionUser::class);

        expect($guard->sanitizer)->toBe($result);
        expect($guard->model)->toBe(ResolverConventionUser::class);
    });

    it('does not invoke the schema guard when no sanitizer resolves', function () {
        config()->set('pii.sanitizers', []);

        $guard = new ResolverRecordingSchemaGuard;
        $resolver = new SanitizerResolver($guard, new ResolverNullDataQualityGuard);

        expect($resolver->resolve(ResolverFakeUser::class))->toBeNull();
        expect($guard->sanitizer)->toBeNull();
        expect($guard->model)->toBeNull();
    });

    it('passes the resolved sanitizer and the model through the data-quality guard', function () {
        config()->set('pii.sanitizers', []);

        $qualityGuard = new ResolverRecordingDataQualityGuard;
        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, $qualityGuard);

        $result = $resolver->resolve(ResolverConventionUser::class);

        expect($qualityGuard->sanitizer)->toBe($result);
        expect($qualityGuard->model)->toBe(ResolverConventionUser::class);
    });

    it('does not invoke the data-quality guard when no sanitizer resolves', function () {
        config()->set('pii.sanitizers', []);

        $qualityGuard = new ResolverRecordingDataQualityGuard;
        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, $qualityGuard);

        expect($resolver->resolve(ResolverFakeUser::class))->toBeNull();
        expect($qualityGuard->sanitizer)->toBeNull();
        expect($qualityGuard->model)->toBeNull();
    });

    it('throws InvalidConfigurationException when a pii.sanitizers entry does not resolve to a Sanitizer', function () {
        config()->set('pii.sanitizers', [
            ResolverFakeUser::class => ResolverNotASanitizer::class,
        ]);

        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, new ResolverNullDataQualityGuard);

        expect(fn () => $resolver->resolve(ResolverFakeUser::class))
            ->toThrow(InvalidConfigurationException::class, ResolverNotASanitizer::class);
    });

    it('falls through to the conventional class when a pii.sanitizers entry is an empty string', function () {
        config()->set('pii.sanitizers', [
            ResolverConventionUser::class => '',
        ]);

        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, new ResolverNullDataQualityGuard);

        expect($resolver->resolve(ResolverConventionUser::class))
            ->toBeInstanceOf(ResolverConventionUserSanitizer::class);
    });

    it('falls through to the conventional class when a pii.sanitizers entry is null', function () {
        config()->set('pii.sanitizers', [
            ResolverConventionUser::class => null,
        ]);

        $resolver = new SanitizerResolver(new ResolverNullSchemaGuard, new ResolverNullDataQualityGuard);

        expect($resolver->resolve(ResolverConventionUser::class))
            ->toBeInstanceOf(ResolverConventionUserSanitizer::class);
    });
}
