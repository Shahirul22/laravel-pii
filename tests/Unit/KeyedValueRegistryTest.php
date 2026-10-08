<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Json;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class KrUser extends Model
    {
        protected $table = 'kr_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class KrUniqueSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => Keyed::pattern('code', '???')];
        }
    }

    class KrPureSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['alias' => Keyed::pattern('alias', '???')];
        }
    }

    class KrMismatchSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['alias' => Keyed::pattern('code', '####')];
        }
    }

    class KrPlainSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['alias' => 'safeEmail'];
        }
    }

    class KrPathSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['alias' => Json::paths([
                'a->b' => Keyed::pattern('alias', '???'),
                'c' => 'safeEmail',
                '0' => Keyed::pattern('alias', '???'),
            ])];
        }
    }

    class KrPathOnUniqueSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => Json::paths(['x' => Keyed::pattern('pathonly', '???')])];
        }
    }

    class KrPathMismatchSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['alias' => Json::paths(['p' => Keyed::pattern('code', '####')])];
        }
    }

    class KrPathPlainSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['alias' => Json::paths(['p' => 'safeEmail'])];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\Values\KeyedValueRegistry;

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x01", 32));

        Schema::create('kr_users', function ($table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('alias')->nullable();
            $table->string('handle')->nullable()->unique();
        });

        foreach (['AB1', 'cd2', '123'] as $code) {
            DB::table('kr_users')->insert(['code' => $code, 'handle' => null]);
        }
    });

    it('classifies a namespace bound to a unique column as unique-bound and one bound to a plain column as pure', function () {
        $registry = app(KeyedValueRegistry::class);

        $registry->register(new KrUser, new KrUniqueSanitizer);
        $registry->register(new KrUser, new KrPureSanitizer);

        expect($registry->isUniqueBound('code'))->toBeTrue();
        expect($registry->isUniqueBound('alias'))->toBeFalse();
        expect($registry->isUniqueBound('never-seen'))->toBeFalse();
    });

    it('forbids the originals of a unique-bound column, compared case-insensitively and as strings', function () {
        $registry = app(KeyedValueRegistry::class);
        $registry->register(new KrUser, new KrUniqueSanitizer);

        expect($registry->isAvailable('code', 'ab1'))->toBeFalse();
        expect($registry->isAvailable('code', 'cd2'))->toBeFalse();
        expect($registry->isAvailable('code', '123'))->toBeFalse();
        expect($registry->isAvailable('code', 'zz9'))->toBeTrue();

        expect(KeyedValueRegistry::comparisonKey('AB1'))->toBe('ab1');
        expect(KeyedValueRegistry::comparisonKey(123))->toBe('123');
    });

    it('rejects two shapes in one namespace', function () {
        $registry = app(KeyedValueRegistry::class);
        $registry->register(new KrUser, new KrUniqueSanitizer);

        try {
            $registry->register(new KrUser, new KrMismatchSanitizer);

            test()->fail('Expected InvalidConfigurationException to be thrown.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->toContain('"code"');
        }
    });

    it('requires the key only when a Keyed field is declared', function () {
        config()->set('pii.keyed.key', null);
        $registry = app(KeyedValueRegistry::class);

        $registry->register(new KrUser, new KrPlainSanitizer);

        expect(fn () => $registry->register(new KrUser, new KrUniqueSanitizer))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('memoises assignments and clears all run state on reset()', function () {
        $registry = app(KeyedValueRegistry::class);
        $registry->register(new KrUser, new KrUniqueSanitizer);

        $digest = str_repeat('d', 16);
        $registry->assign('code', $digest, 'XYZ', 'xyz');

        expect($registry->assigned('code', $digest))->toBe('XYZ');
        expect($registry->isAvailable('code', 'xyz'))->toBeFalse();

        $registry->reset();

        expect($registry->assigned('code', $digest))->toBeNull();
        expect($registry->isUniqueBound('code'))->toBeFalse();
        expect($registry->isAvailable('code', 'ab1'))->toBeTrue();
    });

    it('records a flat binding with a null path', function () {
        $registry = app(KeyedValueRegistry::class);
        $registry->register(new KrUser, new KrUniqueSanitizer);

        expect($registry->bindings('code'))->toBe([
            ['connection' => null, 'table' => 'kr_users', 'column' => 'code', 'path' => null, 'unique' => true],
        ]);
    });

    it('records a Keyed path as a non-unique binding with the path string, in declaration order', function () {
        $registry = app(KeyedValueRegistry::class);
        $registry->register(new KrUser, new KrPathSanitizer);

        expect($registry->bindings('alias'))->toBe([
            ['connection' => null, 'table' => 'kr_users', 'column' => 'alias', 'path' => 'a->b', 'unique' => false],
            ['connection' => null, 'table' => 'kr_users', 'column' => 'alias', 'path' => '0', 'unique' => false],
        ]);
    });

    it('never makes a namespace unique-bound or seeds originals from a path binding', function () {
        $registry = app(KeyedValueRegistry::class);
        $registry->register(new KrUser, new KrPathOnUniqueSanitizer);

        expect($registry->isUniqueBound('pathonly'))->toBeFalse();
        expect($registry->isAvailable('pathonly', 'ab1'))->toBeTrue();
    });

    it('rejects a path whose shape differs from a flat column in the same namespace', function () {
        $registry = app(KeyedValueRegistry::class);
        $registry->register(new KrUser, new KrUniqueSanitizer);

        try {
            $registry->register(new KrUser, new KrPathMismatchSanitizer);

            test()->fail('Expected InvalidConfigurationException to be thrown.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->toContain('"code"');
        }
    });

    it('requires the key when the only Keyed value sits inside a path', function () {
        config()->set('pii.keyed.key', null);
        $registry = app(KeyedValueRegistry::class);

        expect(fn () => $registry->register(new KrUser, new KrPathSanitizer))
            ->toThrow(InvalidConfigurationException::class, 'PII_SANITIZER_KEY');
    });

    it('requires no key and records no binding for a path map without Keyed values', function () {
        config()->set('pii.keyed.key', null);
        $registry = app(KeyedValueRegistry::class);

        $registry->register(new KrUser, new KrPathPlainSanitizer);

        expect($registry->bindings('alias'))->toBe([]);
    });

    it('returns no bindings for an unknown namespace and clears them on reset()', function () {
        $registry = app(KeyedValueRegistry::class);

        expect($registry->bindings('never-seen'))->toBe([]);

        $registry->register(new KrUser, new KrPathSanitizer);
        $registry->reset();

        expect($registry->bindings('alias'))->toBe([]);
    });
}
