<?php

use Illuminate\Support\ServiceProvider;
use Shahirul22\LaravelPiiSanitizer\Contracts\EnvironmentGuardContract;
use Shahirul22\LaravelPiiSanitizer\EnvironmentGuard;
use Shahirul22\LaravelPiiSanitizer\PiiSanitizerServiceProvider;

it('exposes exactly the design-fixed top-level config keys', function () {
    $config = require __DIR__.'/../../config/pii.php';

    expect(array_keys($config))->toBe(['sanitizers', 'protected_columns', 'models', 'environments', 'chunk']);
    expect($config)->not->toHaveKey('connection');
});

it('leaves the two existing keys unchanged', function () {
    $config = require __DIR__.'/../../config/pii.php';

    expect($config['sanitizers'])->toBe([]);
    expect($config['protected_columns'])->toBe(['created_at', 'updated_at', 'deleted_at']);
});

it('fixes the new keys to their documented defaults', function () {
    $config = require __DIR__.'/../../config/pii.php';

    expect($config['environments'])->toBe(['local', 'testing']);
    expect(array_keys($config['chunk']))->toBe(['size', 'min', 'max', 'target_chunks']);
    expect($config['chunk']['min'])->toBe(500);
    expect($config['chunk']['max'])->toBe(5000);
    expect($config['chunk']['target_chunks'])->toBe(20);
});

it('remains publishable via vendor:publish', function () {
    $paths = ServiceProvider::pathsToPublish(PiiSanitizerServiceProvider::class, 'pii-config');

    expect($paths)->not->toBe([]);

    $matched = collect($paths)->first(
        fn (string $destination, string $source): bool => realpath($source) === realpath(__DIR__.'/../../config/pii.php')
    );

    expect($matched)->toBe(config_path('pii.php'));
});

it('binds EnvironmentGuardContract as a singleton', function () {
    $first = app(EnvironmentGuardContract::class);
    $second = app(EnvironmentGuardContract::class);

    expect($first)->toBeInstanceOf(EnvironmentGuard::class);
    expect($first)->toBe($second);
});
