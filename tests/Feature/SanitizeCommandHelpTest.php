<?php

use Illuminate\Support\Facades\Artisan;
use Shahirul22\LaravelPiiSanitizer\Commands\SanitizeCommand;

it('documents every option in --help output', function () {
    Artisan::call('help', ['command_name' => 'pii:sanitize']);

    $output = Artisan::output();

    expect($output)->toContain('--dry-run');
    expect($output)->toContain('Report what would change without writing anything to the database');

    expect($output)->toContain('--force');
    expect($output)->toContain('Allow the run to proceed outside the allowed environments');

    expect($output)->toContain('--chunk');
    expect($output)->toContain('Rows per chunk;');

    expect($output)->toContain('--model');
    expect($output)->toContain('Fully-qualified model class to sanitize; repeatable, defaults to config pii.models');

    expect($output)->toContain('Sanitize PII columns in the database.');

    expect($output)->not->toContain('scaffold');
});

it('exposes no --connection option (AC-12/R5.6: single default connection only)', function () {
    Artisan::call('help', ['command_name' => 'pii:sanitize']);

    $output = Artisan::output();

    expect($output)->not->toContain('--connection');

    $signature = (new SanitizeCommand)->getDefinition();

    expect($signature->hasOption('connection'))->toBeFalse();
});
