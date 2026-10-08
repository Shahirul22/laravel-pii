<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Faker\Generator;
use Illuminate\Support\ServiceProvider;
use Shahirul22\LaravelPiiSanitizer\Commands\SanitizeCommand;
use Shahirul22\LaravelPiiSanitizer\Contracts\DataQualityGuardContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\EnvironmentGuardContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\SchemaGuardContract;
use Shahirul22\LaravelPiiSanitizer\Values\KeyedResolver;
use Shahirul22\LaravelPiiSanitizer\Values\KeyedValueRegistry;
use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\MalaysiaProvider;

class PiiSanitizerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pii.php', 'pii');

        $this->app->singleton(SchemaGuardContract::class, SchemaGuard::class);
        $this->app->singleton(DataQualityGuardContract::class, DataQualityGuard::class);
        $this->app->singleton(SanitizerResolverContract::class, SanitizerResolver::class);

        $this->app->singleton(UniqueColumnInspector::class);
        $this->app->singleton(ForeignKeyInspector::class);
        $this->app->singleton(ForeignKeySuspender::class);
        $this->app->singleton(ReferencedColumnGuard::class);
        $this->app->singleton(UniqueValueTracker::class);
        $this->app->singleton(DistributionSampler::class);
        $this->app->singleton(ReplacementGenerator::class);
        $this->app->singleton(CastAwareEncoder::class);
        $this->app->singleton(ColumnConstraintInspector::class);
        $this->app->singleton(ConstraintValidator::class);
        $this->app->singleton(PagingKeyResolver::class);
        $this->app->singleton(KeyedValueRegistry::class);
        $this->app->singleton(KeyedResolver::class);

        $this->app->singleton(ChunkSizer::class);
        $this->app->bind(SanitizationRunner::class);
        $this->app->singleton(EnvironmentGuardContract::class, EnvironmentGuard::class);

        // Malaysia shorthand (value-generation-primitives spec, R4.5): additive
        // formatter names on the container's Faker, never a locale change. Laravel
        // caches the built Faker per locale process-wide, so a later container gets
        // the same, already-extended instance; the guard keeps it single.
        $this->app->afterResolving(Generator::class, function (Generator $faker): void {
            self::addMalaysiaProvider($faker);
        });

        if ($this->app->resolved(Generator::class)) {
            self::addMalaysiaProvider($this->app->make(Generator::class));
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/pii.php' => config_path('pii.php'),
        ], 'pii-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SanitizeCommand::class,
            ]);
        }
    }

    private static function addMalaysiaProvider(Generator $faker): void
    {
        foreach ($faker->getProviders() as $provider) {
            if ($provider instanceof MalaysiaProvider) {
                return;
            }
        }

        $faker->addProvider(new MalaysiaProvider($faker));
    }
}
