<?php

use Shahirul22\LaravelPiiSanitizer\PiiSanitizerServiceProvider;

it('boots the package service provider without throwing', function () {
    expect(app()->getProvider(PiiSanitizerServiceProvider::class))->not->toBeNull();
});
