<?php

use Shahirul22\LaravelPiiSanitizer\Contracts\EnvironmentGuardContract;
use Shahirul22\LaravelPiiSanitizer\EnvironmentGuard;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeEnvironmentException;
use Shahirul22\LaravelPiiSanitizer\RunOptions;

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

it('proceeds in an allowed environment', function () {
    app()->detectEnvironment(fn () => 'testing');

    $guard = app(EnvironmentGuardContract::class);

    $guard->assertRunnable(new RunOptions);
})->throwsNoExceptions();

it('proceeds in an allowed environment regardless of force and confirmed', function () {
    app()->detectEnvironment(fn () => 'local');

    $guard = new EnvironmentGuard(app());

    $guard->assertRunnable(new RunOptions(force: true, confirmed: false));
})->throwsNoExceptions();

it('refuses a disallowed environment when force is not passed', function () {
    app()->detectEnvironment(fn () => 'production');

    $guard = new EnvironmentGuard(app());

    expect(fn () => $guard->assertRunnable(new RunOptions))
        ->toThrow(
            UnsafeEnvironmentException::class,
            '[laravel-pii-sanitizer] Refusing to run: the application environment is "production". This is a development-time tool and only runs in [local, testing]. Pass --force together with an explicit confirmation to override.'
        );
});

it('refuses when force is passed without confirmation', function () {
    app()->detectEnvironment(fn () => 'production');

    $guard = new EnvironmentGuard(app());

    expect(fn () => $guard->assertRunnable(new RunOptions(force: true, confirmed: false)))
        ->toThrow(
            UnsafeEnvironmentException::class,
            '[laravel-pii-sanitizer] Refusing to run: --force was given in environment "production" but the run was not confirmed. Confirmation is required before sanitizing outside [local, testing].'
        );
});

it('proceeds when force is passed together with confirmation', function () {
    app()->detectEnvironment(fn () => 'production');

    $guard = new EnvironmentGuard(app());

    $guard->assertRunnable(new RunOptions(force: true, confirmed: true));
})->throwsNoExceptions();

it('refuses when only confirmed is passed without force', function () {
    app()->detectEnvironment(fn () => 'production');

    $guard = new EnvironmentGuard(app());

    expect(fn () => $guard->assertRunnable(new RunOptions(force: false, confirmed: true)))
        ->toThrow(UnsafeEnvironmentException::class);
});

it('honors a custom environments allow-list', function () {
    config()->set('pii.environments', ['dev']);

    $guard = new EnvironmentGuard(app());

    app()->detectEnvironment(fn () => 'dev');
    $guard->assertRunnable(new RunOptions);

    app()->detectEnvironment(fn () => 'local');
    expect(fn () => $guard->assertRunnable(new RunOptions))
        ->toThrow(UnsafeEnvironmentException::class, '[dev]');
});

it('falls back to the local/testing default when the environments key is absent or not a list', function () {
    $guard = new EnvironmentGuard(app());

    foreach ([null, 'local', []] as $value) {
        config()->set('pii.environments', $value);

        app()->detectEnvironment(fn () => 'testing');
        $guard->assertRunnable(new RunOptions);

        app()->detectEnvironment(fn () => 'production');
        expect(fn () => $guard->assertRunnable(new RunOptions))
            ->toThrow(UnsafeEnvironmentException::class);
    }
});

it('does not exempt dry runs from the guard', function () {
    app()->detectEnvironment(fn () => 'production');

    $guard = new EnvironmentGuard(app());

    expect(fn () => $guard->assertRunnable(new RunOptions(dryRun: true)))
        ->toThrow(UnsafeEnvironmentException::class);
});
