<?php

namespace {
    use Illuminate\Database\Eloquent\Model;

    class KtRow extends Model
    {
        protected $table = 'kt_rows';

        protected $guarded = [];
    }

    enum KtStatus: string
    {
        case Active = 'active';
    }

    class KtStringable implements Stringable
    {
        public function __toString(): string
        {
            return 'stringable-value';
        }
    }
}

namespace {

    use Faker\Factory;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
    use Shahirul22\LaravelPiiSanitizer\ValueDefinitionResolver;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
    use Shahirul22\LaravelPiiSanitizer\Values\KeyedResolver;

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x01", 32));
    });

    it('accepts valid namespaces', function (string $namespace) {
        expect(Keyed::pattern($namespace, '#')->namespace())->toBe($namespace);
    })->with(['nric', 'a.b:c-d_9', str_repeat('n', 64)]);

    it('rejects invalid namespaces', function (string $namespace) {
        Keyed::pattern($namespace, '#');
    })->with(['', 'has space', "a\x1fb", str_repeat('n', 65)])->throws(InvalidArgumentException::class);

    it('rejects a pattern with no placeholder or a dangling escape', function (string $pattern) {
        Keyed::pattern('ns', $pattern);
    })->with(['ABC', '\#\#', '##\\'])->throws(InvalidArgumentException::class);

    it('maps the pattern alphabet to digits, uppercase letters and uppercase alphanumerics', function () {
        $keyed = Keyed::pattern('alpha', '#?*-\#');

        for ($i = 0; $i < 50; $i++) {
            expect($keyed("input-{$i}", Factory::create(), new KtRow))->toMatch('/^[0-9][A-Z][A-Z0-9]-#$/');
        }
    });

    it('gives identical shapes the same signature and different shapes different ones', function () {
        expect(Keyed::pattern('n', '###')->signature())->toBe(Keyed::pattern('n', '###')->signature());
        expect(Keyed::pattern('n', '###')->signature())->not->toBe(Keyed::pattern('n', '####')->signature());
    });

    it('canonicalises supported inputs', function () {
        expect(KeyedResolver::canonical(null))->toBeNull();
        expect(KeyedResolver::canonical(123))->toBe('123');
        expect(KeyedResolver::canonical('123'))->toBe('123');
        expect(KeyedResolver::canonical(''))->toBe('');
        expect(KeyedResolver::canonical(true))->toBe('1');
        expect(KeyedResolver::canonical(false))->toBe('0');
        expect(KeyedResolver::canonical(KtStatus::Active))->toBe('active');
        expect(KeyedResolver::canonical(new KtStringable))->toBe('stringable-value');
    });

    it('rejects unsupported inputs with the type named', function (mixed $input, string $type) {
        try {
            KeyedResolver::canonical($input);

            test()->fail('Expected InvalidReplacementValueException to be thrown.');
        } catch (InvalidReplacementValueException $exception) {
            expect($exception->getMessage())->toContain('keyed');
            expect($exception->getMessage())->toContain($type);
        }
    })->with([
        'float' => [1.5, 'float'],
        'array' => [['a'], 'array'],
    ]);

    it('is deterministic across separately constructed instances, input types and nulls (AC-1, unit level)', function () {
        $faker = Factory::create();
        $row = new KtRow;

        $a = Keyed::pattern('nric', '######-??-####');
        $b = Keyed::pattern('nric', '######-??-####');

        expect($a('900101015555', $faker, $row))->toBe($b('900101015555', $faker, $row));
        expect($a(123, $faker, $row))->toBe($a('123', $faker, $row));
        expect($a(null, $faker, $row))->toBeNull();
        expect($a('900101015555', $faker, $row))
            ->not->toBe(Keyed::pattern('other', '######-??-####')('900101015555', $faker, $row));
    });

    it('is reachable through ValueDefinitionResolver and ignores the Faker stream', function () {
        $first = Factory::create();
        $first->seed(1);
        $second = Factory::create();
        $second->seed(2);

        $viaResolver = (new ValueDefinitionResolver)->resolve(Keyed::pattern('nric', '######'), 'x', $first, new KtRow);
        $direct = Keyed::pattern('nric', '######')('x', $second, new KtRow);

        expect($viaResolver)->toBe($direct);
    });
}
