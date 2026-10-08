<?php

use Shahirul22\LaravelPiiSanitizer\ColumnConstraints;
use Shahirul22\LaravelPiiSanitizer\ConstraintValidator;
use Shahirul22\LaravelPiiSanitizer\Exceptions\ConstraintViolationException;

it('rejects a null value on a NOT NULL column', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'code', family: 'string', maxLength: null, allowed: null, nullable: false);

    try {
        $validator->assertWritable('App\\Models\\User', 'users', $constraints, null);
        test()->fail('Expected ConstraintViolationException to be thrown.');
    } catch (ConstraintViolationException $exception) {
        expect($exception->getMessage())->toContain('App\\Models\\User');
        expect($exception->getMessage())->toContain('code');
        expect($exception->getMessage())->toContain('users');
        expect($exception->getMessage())->toContain('NOT NULL');
    }
});

it('passes a null value on a nullable column, short-circuiting every other check', function () {
    $validator = new ConstraintValidator;

    $cases = [
        new ColumnConstraints(column: 'a', family: 'integer', maxLength: null, allowed: null, nullable: true),
        new ColumnConstraints(column: 'b', family: 'json', maxLength: null, allowed: null, nullable: true),
        new ColumnConstraints(column: 'c', family: 'boolean', maxLength: null, allowed: null, nullable: true),
        new ColumnConstraints(column: 'd', family: 'string', maxLength: 5, allowed: ['x', 'y'], nullable: true),
    ];

    foreach ($cases as $constraints) {
        $validator->assertWritable('App\\Models\\User', 'users', $constraints, null);
    }

    expect(true)->toBeTrue();
});

it('rejects a value exceeding the column max length, reporting lengths but not the value', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'code', family: 'string', maxLength: 5, allowed: null, nullable: true);

    try {
        $validator->assertWritable('App\\Models\\User', 'users', $constraints, 'abcdefgh');
        test()->fail('Expected ConstraintViolationException to be thrown.');
    } catch (ConstraintViolationException $exception) {
        expect($exception->getMessage())->toContain('length');
        expect($exception->getMessage())->toContain('8');
        expect($exception->getMessage())->toContain('5');
        expect($exception->getMessage())->not->toContain('abcdefgh');
    }
});

it('accepts a multibyte string within the max length using character count, not byte count', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'code', family: 'string', maxLength: 5, allowed: null, nullable: true);

    $validator->assertWritable('App\\Models\\User', 'users', $constraints, 'ééééé');

    expect(true)->toBeTrue();
});

it('rejects a value outside the allowed set, reporting the set but not the value', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'status', family: 'string', maxLength: null, allowed: ['red', 'green'], nullable: true);

    try {
        $validator->assertWritable('App\\Models\\User', 'users', $constraints, 'purple');
        test()->fail('Expected ConstraintViolationException to be thrown.');
    } catch (ConstraintViolationException $exception) {
        expect($exception->getMessage())->toContain('allowed');
        expect($exception->getMessage())->toContain('red, green');
        expect($exception->getMessage())->not->toContain('purple');
    }
});

it('validates a SET family value member-by-member against the allowed set', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'flags', family: 'set', maxLength: null, allowed: ['a', 'b', 'c'], nullable: true);

    $validator->assertWritable('App\\Models\\User', 'users', $constraints, 'a,c');
    $validator->assertWritable('App\\Models\\User', 'users', $constraints, '');

    expect(true)->toBeTrue();

    try {
        $validator->assertWritable('App\\Models\\User', 'users', $constraints, 'a,z');
        test()->fail('Expected ConstraintViolationException to be thrown.');
    } catch (ConstraintViolationException $exception) {
        expect($exception->getMessage())->toContain('allowed');
    }
});

it('validates the integer type family', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'age', family: 'integer', maxLength: null, allowed: null, nullable: true);

    $validator->assertWritable('App\\Models\\User', 'users', $constraints, 12);
    $validator->assertWritable('App\\Models\\User', 'users', $constraints, '-12');

    foreach (['12.5', 'abc', true] as $bad) {
        try {
            $validator->assertWritable('App\\Models\\User', 'users', $constraints, $bad);
            test()->fail('Expected ConstraintViolationException to be thrown for '.get_debug_type($bad));
        } catch (ConstraintViolationException $exception) {
            expect($exception->getMessage())->toContain('type');
            expect($exception->getMessage())->toContain('integer');
        }
    }
});

it('validates the decimal type family', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'price', family: 'decimal', maxLength: null, allowed: null, nullable: true);

    $validator->assertWritable('App\\Models\\User', 'users', $constraints, 1);
    $validator->assertWritable('App\\Models\\User', 'users', $constraints, 1.5);
    $validator->assertWritable('App\\Models\\User', 'users', $constraints, '1.5');

    foreach (['abc', true] as $bad) {
        try {
            $validator->assertWritable('App\\Models\\User', 'users', $constraints, $bad);
            test()->fail('Expected ConstraintViolationException to be thrown for '.get_debug_type($bad));
        } catch (ConstraintViolationException $exception) {
            expect($exception->getMessage())->toContain('type');
            expect($exception->getMessage())->toContain('decimal');
        }
    }
});

it('validates the boolean type family', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'flag', family: 'boolean', maxLength: null, allowed: null, nullable: true);

    foreach ([true, false, 0, 1, '0', '1'] as $good) {
        $validator->assertWritable('App\\Models\\User', 'users', $constraints, $good);
    }

    expect(true)->toBeTrue();

    foreach ([2, 'yes'] as $bad) {
        try {
            $validator->assertWritable('App\\Models\\User', 'users', $constraints, $bad);
            test()->fail('Expected ConstraintViolationException to be thrown for '.get_debug_type($bad));
        } catch (ConstraintViolationException $exception) {
            expect($exception->getMessage())->toContain('type');
            expect($exception->getMessage())->toContain('boolean');
        }
    }
});

it('validates the datetime type family', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'created_at', family: 'datetime', maxLength: null, allowed: null, nullable: true);

    $validator->assertWritable('App\\Models\\User', 'users', $constraints, '2024-01-01');
    $validator->assertWritable('App\\Models\\User', 'users', $constraints, 1700000000);

    foreach ([1.5, true] as $bad) {
        try {
            $validator->assertWritable('App\\Models\\User', 'users', $constraints, $bad);
            test()->fail('Expected ConstraintViolationException to be thrown for '.get_debug_type($bad));
        } catch (ConstraintViolationException $exception) {
            expect($exception->getMessage())->toContain('type');
            expect($exception->getMessage())->toContain('datetime');
        }
    }
});

it('validates the json type family', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'meta', family: 'json', maxLength: null, allowed: null, nullable: true);

    $validator->assertWritable('App\\Models\\User', 'users', $constraints, '{"a":1}');
    $validator->assertWritable('App\\Models\\User', 'users', $constraints, 'null');
    $validator->assertWritable('App\\Models\\User', 'users', $constraints, ['a' => 1]);

    try {
        $validator->assertWritable('App\\Models\\User', 'users', $constraints, '{not json');
        test()->fail('Expected ConstraintViolationException to be thrown.');
    } catch (ConstraintViolationException $exception) {
        expect($exception->getMessage())->toContain('type');
        expect($exception->getMessage())->toContain('json');
    }
});

it('accepts any scalar for the string type family', function () {
    $validator = new ConstraintValidator;
    $constraints = new ColumnConstraints(column: 'name', family: 'string', maxLength: null, allowed: null, nullable: true);

    foreach (['x', 1, 1.5, true] as $good) {
        $validator->assertWritable('App\\Models\\User', 'users', $constraints, $good);
    }

    expect(true)->toBeTrue();
});

it('never validates the binary or other type families', function () {
    $validator = new ConstraintValidator;

    $binary = new ColumnConstraints(column: 'blob', family: 'binary', maxLength: null, allowed: null, nullable: true);
    $other = new ColumnConstraints(column: 'misc', family: 'other', maxLength: null, allowed: null, nullable: true);

    $validator->assertWritable('App\\Models\\User', 'users', $binary, ['not', 'a', 'scalar']);
    $validator->assertWritable('App\\Models\\User', 'users', $other, ['not', 'a', 'scalar']);

    expect(true)->toBeTrue();
});
