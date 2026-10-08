<?php

use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Values\KeyedKey;

it('throws when no key is configured', function () {
    config()->set('pii.keyed.key', null);

    KeyedKey::fromConfig();
})->throws(InvalidConfigurationException::class);

it('throws for an empty-string key', function () {
    config()->set('pii.keyed.key', '');

    KeyedKey::fromConfig();
})->throws(InvalidConfigurationException::class);

it('throws for a raw key shorter than 32 bytes', function () {
    config()->set('pii.keyed.key', str_repeat('a', 31));

    KeyedKey::fromConfig();
})->throws(InvalidConfigurationException::class);

it('returns a raw 32-byte key unchanged', function () {
    $bytes = str_repeat("\x01", 32);
    config()->set('pii.keyed.key', $bytes);

    expect(KeyedKey::fromConfig())->toBe($bytes);
});

it('decodes a base64:-prefixed key', function () {
    $bytes = random_bytes(32);
    config()->set('pii.keyed.key', 'base64:'.base64_encode($bytes));

    expect(KeyedKey::fromConfig())->toBe($bytes);
});

it('throws for a base64:-prefixed key that decodes to fewer than 32 bytes', function () {
    config()->set('pii.keyed.key', 'base64:'.base64_encode(random_bytes(16)));

    KeyedKey::fromConfig();
})->throws(InvalidConfigurationException::class);

it('throws for a base64:-prefixed key that is not valid base64', function () {
    config()->set('pii.keyed.key', 'base64:!!!not-base64');

    KeyedKey::fromConfig();
})->throws(InvalidConfigurationException::class);

it('names the env var in the message and never echoes the configured key', function () {
    config()->set('pii.keyed.key', 'short-secret-value');

    try {
        KeyedKey::fromConfig();

        test()->fail('Expected InvalidConfigurationException to be thrown.');
    } catch (InvalidConfigurationException $exception) {
        expect($exception->getMessage())->toContain('PII_SANITIZER_KEY');
        expect($exception->getMessage())->not->toContain('short-secret-value');
    }
});
