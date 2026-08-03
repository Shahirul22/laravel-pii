<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Shahirul22\LaravelPiiSanitizer\PiiSanitizerServiceProvider;

beforeEach(function () {
    if (file_exists(config_path('pii.php'))) {
        @unlink(config_path('pii.php'));
    }
});

afterEach(function () {
    @unlink(config_path('pii.php'));
});

it('publishes config/pii.php under the pii-config tag', function () {
    Artisan::call('vendor:publish', ['--tag' => 'pii-config', '--force' => true]);

    expect(file_exists(config_path('pii.php')))->toBeTrue();
    expect(file_get_contents(config_path('pii.php')))->toContain("'environments'");
});

it('lets published values override the mergeConfigFrom package defaults', function () {
    $contents = <<<'PHP'
<?php

return [
    'sanitizers' => [],
    'protected_columns' => ['created_at', 'updated_at', 'deleted_at'],
    'models' => [],
    'environments' => ['local', 'testing', 'staging'],
    'chunk' => [
        'size' => null,
        'min' => 500,
        'max' => 5000,
        'target_chunks' => 20,
    ],
];
PHP;

    file_put_contents(config_path('pii.php'), $contents);

    $this->refreshApplication();

    expect(config('pii.environments'))->toBe(['local', 'testing', 'staging']);
});

it('registers exactly one publish group for the provider', function () {
    $paths = ServiceProvider::pathsToPublish(PiiSanitizerServiceProvider::class);

    expect($paths)->not->toBeEmpty();

    $groups = array_keys(ServiceProvider::$publishGroups);

    expect($groups)->toContain('pii-config');
    expect($groups)->not->toContain('pii-sanitizer-config');
});
