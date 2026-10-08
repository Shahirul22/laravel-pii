<?php

use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
use Shahirul22\LaravelPiiSanitizer\Values\Format\FormatPreservingValue;
use Shahirul22\LaravelPiiSanitizer\Values\Format\KeepLength;
use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
use Shahirul22\LaravelPiiSanitizer\Values\KeyedResolver;

// BUG-26: the key bytes and the raw input value must never appear in the
// arguments of a stack trace (zend.exception_ignore_args is Off by default on
// the CLI), so every parameter that carries one is #[\SensitiveParameter].

it('marks every parameter that carries the key or a raw input value as sensitive', function (string $class, string $method, array $parameters) {
    $reflection = new ReflectionMethod($class, $method);

    foreach ($parameters as $name) {
        $parameter = collect($reflection->getParameters())->firstWhere('name', $name);

        expect($parameter)->not->toBeNull();
        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1, "{$class}::{$method}() \${$name}");
    }
})->with([
    'KeyedResolver::resolve()' => [KeyedResolver::class, 'resolve', ['input']],
    'KeyedResolver::canonical()' => [KeyedResolver::class, 'canonical', ['value']],
    'KeyedResolver::seed()' => [KeyedResolver::class, 'seed', ['key', 'canonical']],
    'KeyedResolver::candidate()' => [KeyedResolver::class, 'candidate', ['key', 'canonical', 'value']],
    'KeyedResolver::digest()' => [KeyedResolver::class, 'digest', ['key', 'canonical']],
    'Keyed::__invoke()' => [Keyed::class, '__invoke', ['value']],
    'FormatPreservingValue::generate()' => [FormatPreservingValue::class, 'generate', ['value']],
]);

it('keeps the key and the input out of a real stack trace', function () {
    $key = str_repeat('K', 32);
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        KeyedResolver::candidate($key, 'ns', "bad-\xff-input", 0, new KeepLength, "bad-\xff-input");
        test()->fail('expected an exception');
    } catch (InvalidReplacementValueException $e) {
        $frames = array_filter($e->getTrace(), fn (array $frame): bool => ($frame['class'] ?? null) === KeyedResolver::class);
        $args = print_r(array_map(fn (array $frame): array => $frame['args'] ?? [], $frames), true);

        expect($frames)->not->toBe([]);
        expect($args)->toContain('SensitiveParameterValue');
        expect($args)->not->toContain($key);
        expect($args)->not->toContain('bad-');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});
