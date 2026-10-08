<?php

namespace {
    use Faker\Generator;
    use Illuminate\Database\Eloquent\Model;
    use Random\Engine\Mt19937;
    use Random\Randomizer;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\Values\Format;

    class FhRow extends Model
    {
        protected $table = 'fh_rows';

        protected $guarded = [];
    }

    enum FhStatus: string
    {
        case Active = 'active';
    }

    class FhStringable implements Stringable
    {
        public function __toString(): string
        {
            return 'Stringable-42';
        }
    }

    class FhMaskedIcGenerator implements ValueGenerator
    {
        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            return Format::keepLast(4)($value, $faker, $row);
        }
    }

    function fhRandom(int $seed = 42): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }

    function fhAssertClassShape(string $original, string $output): void
    {
        $in = mb_str_split($original, 1, 'UTF-8');
        $out = mb_str_split($output, 1, 'UTF-8');

        expect($out)->toHaveCount(count($in));

        foreach ($in as $i => $character) {
            if (preg_match('/^\p{N}$/u', $character) === 1) {
                expect($out[$i])->toMatch('/^[0-9]$/');
            } elseif (preg_match('/^\p{Lu}$/u', $character) === 1) {
                expect($out[$i])->toMatch('/^[A-Z]$/');
            } elseif (preg_match('/^\p{L}$/u', $character) === 1) {
                expect($out[$i])->toMatch('/^[a-z]$/');
            } else {
                expect($out[$i])->toBe($character);
            }
        }
    }
}

namespace {

    use Faker\Factory;
    use Shahirul22\LaravelPiiSanitizer\Contracts\RandomizedGenerator;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
    use Shahirul22\LaravelPiiSanitizer\ValueDefinitionResolver;
    use Shahirul22\LaravelPiiSanitizer\Values\Format;
    use Shahirul22\LaravelPiiSanitizer\Values\Format\KeepEmailDomain;
    use Shahirul22\LaravelPiiSanitizer\Values\Format\KeepLast;
    use Shahirul22\LaravelPiiSanitizer\Values\Format\KeepLength;
    use Shahirul22\LaravelPiiSanitizer\Values\Format\KeepPrefix;
    use Shahirul22\LaravelPiiSanitizer\Values\Format\Pattern;
    use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

    it('returns the right helper types from the factory', function () {
        expect(Format::keepLength())->toBeInstanceOf(KeepLength::class);
        expect(Format::keepPrefix(3))->toBeInstanceOf(KeepPrefix::class);
        expect(Format::keepLast(4))->toBeInstanceOf(KeepLast::class);
        expect(Format::keepEmailDomain())->toBeInstanceOf(KeepEmailDomain::class);

        foreach ([Format::keepLength(), Format::keepPrefix(3), Format::keepLast(4), Format::keepEmailDomain()] as $helper) {
            expect($helper)->toBeInstanceOf(RandomizedValue::class);
            expect($helper)->toBeInstanceOf(ValueGenerator::class);
            expect($helper)->toBeInstanceOf(RandomizedGenerator::class);
        }
    });

    it('rejects invalid constructor arguments', function (callable $make) {
        $make();
    })->with([
        'prefix zero' => [fn () => Format::keepPrefix(0)],
        'prefix negative' => [fn () => Format::keepPrefix(-1)],
        'last zero' => [fn () => Format::keepLast(0)],
        'empty mask' => [fn () => Format::keepLength('')],
        'two-char mask' => [fn () => Format::keepLength('**')],
        'invalid utf8 mask' => [fn () => Format::keepLength("\xff")],
    ])->throws(InvalidArgumentException::class);

    it('accepts a one-code-point multibyte mask', function () {
        expect(Format::keepLength('é'))->toBeInstanceOf(KeepLength::class);
    });

    it('has exact signatures', function () {
        expect(Format::keepLength()->signature())->toBe('keepLength');
        expect(Format::keepLength('*')->signature())->toBe('keepLength:*');
        expect(Format::keepPrefix(3)->signature())->toBe('keepPrefix:3');
        expect(Format::keepLast(4)->signature())->toBe('keepLast:4');
        expect(Format::keepEmailDomain()->signature())->toBe('keepEmailDomain');
    });

    it('keeps length and separators and replaces letters and digits by class (keepLength)', function () {
        for ($seed = 1; $seed <= 20; $seed++) {
            fhAssertClassShape('Ahmad bin Ali-0123', Format::keepLength()->generate('Ahmad bin Ali-0123', fhRandom($seed)));
        }

        expect(Format::keepLength()->generate('Ahmad bin Ali-0123', fhRandom(42)))->not->toBe('Ahmad bin Ali-0123');
    });

    it('replaces non-ASCII letters and digits rather than keeping them', function () {
        $input = 'Zoë Ñúñez 李明 ٣٤';
        $output = Format::keepLength()->generate($input, fhRandom());

        fhAssertClassShape($input, $output);
        expect($output)->toMatch('/^[\x00-\x7F]*$/');
    });

    it('masks letters and digits with a fixed character without drawing', function () {
        expect(Format::keepLength('*')->generate('Ahmad 0123', fhRandom(1)))->toBe('***** ****');
        expect(Format::keepLength('*')->generate('Ahmad 0123', fhRandom(2)))->toBe('***** ****');
        expect(Format::keepLength('•')->generate('ab-12', fhRandom()))->toBe('••-••');
    });

    it('keeps a prefix by code points and replaces the rest (keepPrefix)', function () {
        $output = Format::keepPrefix(3)->generate('ACC-00012345', fhRandom());

        expect($output)->toStartWith('ACC');
        expect(strlen($output))->toBe(12);
        fhAssertClassShape('-00012345', substr($output, 3));
        expect($output)->not->toBe('ACC-00012345');

        $multibyte = Format::keepPrefix(2)->generate('Ñú-12', fhRandom());

        expect($multibyte)->toStartWith('Ñú');
        expect(mb_strlen($multibyte))->toBe(5);
    });

    it('keeps the last N by code points and replaces the rest (keepLast)', function () {
        $output = Format::keepLast(4)->generate('0123456789', fhRandom());

        expect($output)->toEndWith('6789');
        expect(strlen($output))->toBe(10);
        expect(substr($output, 0, 6))->toMatch('/^[0-9]{6}$/');
        expect($output)->not->toBe('0123456789');

        expect(Format::keepLast(2)->generate('ab-éü', fhRandom()))->toEndWith('éü');
    });

    it('replaces the whole value when it is no longer than the kept count', function (callable $make) {
        expect($make()->generate('1234', fhRandom()))->toMatch('/^\d{4}$/')->not->toBe('1234');
        expect($make()->generate('12', fhRandom()))->toMatch('/^\d{2}$/')->not->toBe('12');
        expect($make()->generate('', fhRandom()))->toBe('');
    })->with([
        'keepPrefix' => [fn () => Format::keepPrefix(4)],
        'keepLast' => [fn () => Format::keepLast(4)],
    ]);

    it('keeps the email domain byte-exact and replaces the local part (keepEmailDomain)', function () {
        $output = Format::keepEmailDomain()->generate('John.Doe+news@Example.COM', fhRandom());

        expect($output)->toEndWith('@Example.COM');
        fhAssertClassShape('John.Doe+news', substr($output, 0, -strlen('@Example.COM')));
    });

    it('splits keepEmailDomain at the last @', function () {
        $output = Format::keepEmailDomain()->generate('"a@b"@corp.example', fhRandom());

        expect($output)->toEndWith('@corp.example');

        $local = substr($output, 0, -strlen('@corp.example'));

        expect($local)->toHaveLength(5);
        expect($local[0])->toBe('"');
        expect($local[2])->toBe('@');
        expect($local[4])->toBe('"');
    });

    it('falls back to keepLength when there is no usable email split', function (string $input) {
        $output = Format::keepEmailDomain()->generate($input, fhRandom());

        fhAssertClassShape($input, $output);

        if ($input === '@example.com') {
            expect($output)->not->toEndWith('example.com');
        }
    })->with(['not-an-email', '@example.com', 'john@']);

    it('stringifies int input and canonicalises bool, enum and Stringable like Keyed', function () {
        $output = Format::keepLast(4)->generate(60123456789, fhRandom());

        expect($output)->toBeString()->toEndWith('6789');
        expect(strlen($output))->toBe(11);

        expect(Format::keepPrefix(3)->generate(FhStatus::Active, fhRandom()))->toStartWith('act');
        expect(Format::keepPrefix(10)->generate(new FhStringable, fhRandom()))->toStartWith('Stringable');
        expect(Format::keepLength()->generate(true, fhRandom()))->toMatch('/^\d$/');
    });

    it('rejects unsupported input types naming the helper', function (mixed $input, string $type) {
        try {
            Format::keepLast(4)->generate($input, fhRandom());
            $this->fail('expected an exception');
        } catch (InvalidReplacementValueException $e) {
            expect($e->getMessage())->toContain('keepLast:4')->toContain($type);
        }
    })->with([
        [1.5, 'float'],
        [['a'], 'array'],
        [new stdClass, 'stdClass'],
    ]);

    it('rejects invalid UTF-8 input on every helper', function (RandomizedGenerator $helper) {
        try {
            $helper->generate("ab\xffcd", fhRandom());
            $this->fail('expected an exception');
        } catch (InvalidReplacementValueException $e) {
            expect($e->getMessage())->toContain($helper->signature())->toContain('invalid-utf8');
        }
    })->with([
        fn () => Format::keepLength(),
        fn () => Format::keepPrefix(2),
        fn () => Format::keepLast(2),
        fn () => Format::keepEmailDomain(),
    ]);

    it('throws a LogicException when generate() is called with null directly', function () {
        Format::keepLength()->generate(null, fhRandom());
    })->throws(LogicException::class);

    it('draws one getInt per replaced letter or digit, left to right', function () {
        $a = fhRandom(7);
        $b = fhRandom(7);

        $actual = Format::keepLast(2)->generate('A-b-1-xy', $a);
        $expected = Pattern::ALPHA[$b->getInt(0, 25)].'-'.'abcdefghijklmnopqrstuvwxyz'[$b->getInt(0, 25)].'-'.(string) $b->getInt(0, 9).'-xy';

        expect($actual)->toBe($expected);
    });

    it('returns null for null and is reproducible under a seeded Faker in bare mode', function () {
        $faker = Factory::create();
        $helper = Format::keepLast(4);

        expect($helper(null, $faker, new FhRow))->toBeNull();

        $faker->seed(5);
        $first = $helper('0123456789', $faker, new FhRow);
        $faker->seed(5);
        $second = $helper('0123456789', $faker, new FhRow);

        expect($second)->toBe($first);
        expect($first)->toEndWith('6789');
    });

    it('composes through ValueDefinitionResolver as an instance, closure and class-string', function () {
        $resolver = new ValueDefinitionResolver;
        $faker = Factory::create();
        $row = new FhRow;

        expect($resolver->resolve(Format::keepLast(4), '0123456789', $faker, $row))->toEndWith('6789');
        expect($resolver->resolve(fn ($v, $f, $r) => Format::keepLast(4)($v, $f, $r), '0123456789', $faker, $row))->toEndWith('6789');
        expect($resolver->resolve(FhMaskedIcGenerator::class, '900101015555', $faker, $row))->toEndWith('5555');
    });

    it('is never static', function (RandomizedGenerator $helper) {
        expect((new ValueDefinitionResolver)->isStatic($helper, Factory::create()))->toBeFalse();
    })->with([
        fn () => Format::keepLength(),
        fn () => Format::keepPrefix(2),
        fn () => Format::keepLast(2),
        fn () => Format::keepEmailDomain(),
    ]);
}
