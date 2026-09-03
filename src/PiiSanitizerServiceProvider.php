<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Support\ServiceProvider;
use Shahirul22\LaravelPiiSanitizer\Commands\SanitizeCommand;
use Shahirul22\LaravelPiiSanitizer\Contracts\DataQualityGuardContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\EnvironmentGuardContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;
use Shahirul22\LaravelPiiSanitizer\Contracts\SchemaGuardContract;

class PiiSanitizerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pii.php', 'pii');

        $this->app->singleton(SchemaGuardContract::class, SchemaGuard::class);
        $this->app->singleton(DataQualityGuardContract::class, DataQualityGuard::class);
        $this->app->singleton(SanitizerResolverContract::class, SanitizerResolver::class);

        $this->app->singleton(UniqueColumnInspector::class);
        $this->app->singleton(UniqueValueTracker::class);
        $this->app->singleton(DistributionSampler::class);
        $this->app->singleton(ReplacementGenerator::class);

        $this->app->singleton(ChunkSizer::class);
        $this->app->bind(SanitizationRunner::class);
        $this->app->singleton(EnvironmentGuardContract::class, EnvironmentGuard::class);
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
}
