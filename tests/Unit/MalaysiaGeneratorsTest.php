<?php

namespace {
    use Illuminate\Database\Eloquent\Model;

    class MlyRow extends Model
    {
        protected $table = 'mly_rows';

        protected $guarded = [];
    }
}

namespace {

    use Faker\Factory;
    use Random\Engine\Mt19937;
    use Random\Randomizer;
    use Shahirul22\LaravelPiiSanitizer\Contracts\RandomizedGenerator;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\Tests\Support\MalaysiaFormats;
    use Shahirul22\LaravelPiiSanitizer\ValueDefinitionResolver;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\BankAccount;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\Nric;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\Sst;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\State;

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x01", 32));
    });

    it('returns RandomizedValue instances from every factory', function () {
        $expected = [
            [Malaysia::nric(), Nric::class],
            [Malaysia::sst(), Sst::class],
            [Malaysia::state(), State::class],
            [Malaysia::bankAccount(), BankAccount::class],
        ];

        foreach ($expected as [$instance, $class]) {
            expect($instance)->toBeInstanceOf($class);
            expect($instance)->toBeInstanceOf(ValueGenerator::class);
            expect($instance)->toBeInstanceOf(RandomizedGenerator::class);
        }
    });

    it('generates a structurally valid NRIC', function (bool $hyphen, ?string $gender) {
        for ($seed = 1; $seed <= 500; $seed++) {
            $value = Malaysia::nric($hyphen, $gender)->generate('ignored', new Randomizer(new Mt19937($seed)));

            expect(MalaysiaFormats::nricIsValid($value, $hyphen, $gender))->toBeTrue("seed {$seed}: {$value}");
        }
    })->with([
        'plain/any' => [false, null],
        'hyphen/any' => [true, null],
        'plain/male' => [false, 'male'],
        'hyphen/female' => [true, 'female'],
    ]);

    it('spreads NRIC birthdates over both centuries and both gender parities', function () {
        $centuries = [];
        $parities = [];

        for ($seed = 1; $seed <= 2000; $seed++) {
            $value = Malaysia::nric()->generate('x', new Randomizer(new Mt19937($seed)));
            $centuries[MalaysiaFormats::nricCentury($value)] = true;
            $parities[(int) substr($value, -1) % 2] = true;
        }

        $centuryKeys = array_keys($centuries);
        sort($centuryKeys);
        $parityKeys = array_keys($parities);
        sort($parityKeys);

        expect($centuryKeys)->toBe([1900, 2000]);
        expect($parityKeys)->toBe([0, 1]);
    });

    it('rejects an unknown NRIC gender at construction', function (string $gender) {
        expect(fn () => Malaysia::nric(false, $gender))->toThrow(InvalidArgumentException::class);
    })->with(['Male', 'm', '']);

    it('generates a structurally valid SST number', function () {
        $prefixes = [];

        for ($seed = 1; $seed <= 500; $seed++) {
            $value = Malaysia::sst()->generate('x', new Randomizer(new Mt19937($seed)));

            expect(MalaysiaFormats::sstIsValid($value))->toBeTrue("seed {$seed}: {$value}");
            $prefixes[substr($value, 0, 4)] = true;
        }

        expect(array_keys($prefixes))->toEqualCanonicalizing(['W10-', 'B16-']);
    });

    it('picks a state from the fixed list', function () {
        $seen = [];

        for ($seed = 1; $seed <= 2000; $seed++) {
            $value = Malaysia::state()->generate('x', new Randomizer(new Mt19937($seed)));

            expect(in_array($value, MalaysiaFormats::STATES, true))->toBeTrue();
            $seen[$value] = true;
        }

        expect(array_keys($seen))->toEqualCanonicalizing(MalaysiaFormats::STATES);
    });

    it('generates a bank account of the bank\'s length', function (string $bank) {
        for ($seed = 1; $seed <= 200; $seed++) {
            $value = Malaysia::bankAccount($bank)->generate('x', new Randomizer(new Mt19937($seed)));

            expect(MalaysiaFormats::bankAccountIsValid($value, $bank))->toBeTrue("seed {$seed}: {$value}");
        }
    })->with(array_keys(MalaysiaFormats::BANK_LENGTHS));

    it('mixes bank lengths when no bank is given', function () {
        $lengths = [];

        for ($seed = 1; $seed <= 500; $seed++) {
            $value = Malaysia::bankAccount()->generate('x', new Randomizer(new Mt19937($seed)));

            expect(MalaysiaFormats::bankAccountIsValid($value))->toBeTrue("seed {$seed}: {$value}");
            $lengths[strlen($value)] = true;
        }

        expect(count($lengths))->toBeGreaterThan(1);
    });

    it('rejects an unknown bank at construction', function () {
        expect(fn () => Malaysia::bankAccount('Nope'))->toThrow(InvalidArgumentException::class, 'Maybank');
        expect(fn () => Malaysia::bankAccount('maybank'))->toThrow(InvalidArgumentException::class);
    });

    it('keeps null and ignores the input value when used bare', function () {
        $factories = [
            fn () => Malaysia::nric(),
            fn () => Malaysia::sst(),
            fn () => Malaysia::state(),
            fn () => Malaysia::bankAccount(),
        ];

        foreach ($factories as $make) {
            expect($make()(null, Factory::create(), new MlyRow))->toBeNull();

            $first = Factory::create();
            $first->seed(7);
            $a = $make()('a', $first, new MlyRow);

            $second = Factory::create();
            $second->seed(7);
            $b = $make()('b', $second, new MlyRow);

            expect($a)->toBeString()->toBe($b);
        }
    });

    it('resolves as an ordinary value-definition and is never static', function () {
        $cases = [
            [Malaysia::nric(), fn ($v) => MalaysiaFormats::nricIsValid($v)],
            [Malaysia::sst(), fn ($v) => MalaysiaFormats::sstIsValid($v)],
            [Malaysia::state(), fn ($v) => in_array($v, MalaysiaFormats::STATES, true)],
            [Malaysia::bankAccount(), fn ($v) => MalaysiaFormats::bankAccountIsValid($v)],
        ];

        foreach ($cases as [$instance, $oracle]) {
            $resolver = new ValueDefinitionResolver;

            expect($oracle($resolver->resolve($instance, 'x', Factory::create(), new MlyRow)))->toBeTrue();
            expect($resolver->isStatic($instance, Factory::create()))->toBeFalse();
        }
    });

    it('gives signatures that differ by parameters only', function () {
        expect(Malaysia::nric()->signature())->toBe('nric:plain:any');
        expect(Malaysia::nric(true, 'female')->signature())->toBe('nric:hyphen:female');
        expect(Malaysia::bankAccount()->signature())->toBe('bankAccount:any');
        expect(Malaysia::bankAccount('BSN')->signature())->toBe('bankAccount:BSN');
        expect(Malaysia::sst()->signature())->toBe('sst');
        expect(Malaysia::state()->signature())->toBe('state');

        expect(Keyed::using('n', Malaysia::nric())->signature())
            ->toBe(Keyed::using('n', Malaysia::nric())->signature())
            ->not->toBe(Keyed::using('n', Malaysia::nric(true))->signature());
    });

    it('is deterministic and valid when wrapped in Keyed', function (Closure $make, string $input, Closure $oracle) {
        $first = Keyed::using('ns', $make())($input, Factory::create(), new MlyRow);

        $seeded = Factory::create();
        $seeded->seed(123);
        $second = Keyed::using('ns', $make())($input, $seeded, new MlyRow);

        expect($first)->toBeString()->toBe($second);
        expect($oracle($first))->toBeTrue();
        expect(Keyed::using('ns', $make())(null, Factory::create(), new MlyRow))->toBeNull();
    })->with([
        'nric' => [fn () => Malaysia::nric(), '900101015555', fn ($v) => MalaysiaFormats::nricIsValid($v)],
        'sst' => [fn () => Malaysia::sst(), 'W10-1808-32000064', fn ($v) => MalaysiaFormats::sstIsValid($v)],
        'state' => [fn () => Malaysia::state(), 'Selangor', fn ($v) => in_array($v, MalaysiaFormats::STATES, true)],
        'bank' => [fn () => Malaysia::bankAccount(), '1234567890', fn ($v) => MalaysiaFormats::bankAccountIsValid($v)],
    ]);

    it('treats an integer NRIC input like its string form under Keyed', function () {
        $faker = Factory::create();

        expect(Keyed::using('nric', Malaysia::nric())(900101015555, $faker, new MlyRow))
            ->toBe(Keyed::using('nric', Malaysia::nric())('900101015555', $faker, new MlyRow));
    });

    it('matches the known-answer vectors', function () {
        $faker = Factory::create();
        $row = new MlyRow;

        expect(Keyed::using('nric', Malaysia::nric())('900101015555', $faker, $row))->toBe('060420484801');
        expect(Keyed::using('nric', Malaysia::nric(true, 'female'))('900101015555', $faker, $row))->toBe('060420-48-4802');
        expect(Keyed::using('sst', Malaysia::sst())('W10-1808-32000064', $faker, $row))->toBe('B16-2503-37600137');
        expect(Keyed::using('region', Malaysia::state())('Selangor', $faker, $row))->toBe('Johor');
        expect(Keyed::using('bank', Malaysia::bankAccount())('1234567890', $faker, $row))->toBe('88464751359');
        expect(Keyed::using('bank', Malaysia::bankAccount('Maybank'))('1234567890', $faker, $row))->toBe('988464751359');
    });

    it('matches the bridge-mode known-answer vectors', function () {
        $random = fn () => new Randomizer(new Mt19937(2026));

        expect(Malaysia::nric()->generate(null, $random()))->toBe('781201248986');
        expect(Malaysia::sst()->generate(null, $random()))->toBe('B16-2411-73871898');
        expect(Malaysia::state()->generate(null, $random()))->toBe('Kedah');
        expect(Malaysia::bankAccount()->generate(null, $random()))->toBe('18617700973442');
    });
}
