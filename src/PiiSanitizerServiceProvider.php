<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Support\ServiceProvider;
use Shahirul22\LaravelPiiSanitizer\Commands\SanitizeCommand;

class PiiSanitizerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pii.php', 'pii');
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
