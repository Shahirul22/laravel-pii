<?php

namespace {
    use Illuminate\Database\Eloquent\Model;

    class FkRow extends Model
    {
        protected $table = 'fk_rows';

        protected $guarded = [];
    }
}

namespace {

    use Faker\Factory;
    use Shahirul22\LaravelPiiSanitizer\Contracts\RandomizedGenerator;
    use Shahirul22\LaravelPiiSanitizer\Values\Format;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
    use Shahirul22\LaravelPiiSanitizer\Values\KeyedResolver;

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x01", 32));
    });

    it('matches the known-answer vectors', function () {
        expect(Keyed::using('phone', Format::keepLast(4))('0123456789', Factory::create(), new FkRow))->toBe('6375266789');
        expect(Keyed::using('email', Format::keepEmailDomain())('John.Doe@example.com', Factory::create(), new FkRow))->toBe('Ydaq.Jog@example.com');

        expect(bin2hex(KeyedResolver::seed(str_repeat("\x01", 32), 'phone', '0123456789', 0)))
            ->toBe('be66554f124d17129da7f5d448064c13c16f830eb12d86fa78c93d73f1c305a7');
        expect(bin2hex(KeyedResolver::seed(str_repeat("\x01", 32), 'email', 'John.Doe@example.com', 0)))
            ->toBe('59d11b4602483e73c5606044c448cde0adaff971d0b150c79dc20dc519e08901');
    });

    it('is deterministic across calls and Faker seeds while keeping the preserved portion', function (callable $make, string $input, callable $check) {
        $first = Keyed::using('ns', $make())($input, Factory::create(), new FkRow);

        $seeded = Factory::create();
        $seeded->seed(123);
        $second = Keyed::using('ns', $make())($input, $seeded, new FkRow);

        expect($second)->toBe($first);
        expect($first)->not->toBe($input);
        $check($input, $first);
    })->with([
        'keepLast' => [fn () => Format::keepLast(4), '0123456789', fn ($in, $out) => expect($out)->toEndWith('6789')],
        'keepPrefix' => [fn () => Format::keepPrefix(3), 'ACC-00012345', fn ($in, $out) => expect($out)->toStartWith('ACC')],
        'keepEmailDomain' => [fn () => Format::keepEmailDomain(), 'aminah.yusof@corp.example', fn ($in, $out) => expect($out)->toEndWith('@corp.example')],
        'keepLength' => [fn () => Format::keepLength(), 'Ahmad bin Ali-0123', fn ($in, $out) => expect($out)->toMatch('/^[A-Z][a-z]{4} [a-z]{3} [A-Z][a-z]{2}-[0-9]{4}$/')],
    ]);

    it('gives the same output for an int and its string form', function () {
        $keyed = Keyed::using('phone', Format::keepLast(4));

        expect($keyed(60123456789, Factory::create(), new FkRow))->toBe($keyed('60123456789', Factory::create(), new FkRow));
    });

    it('returns null for null input', function () {
        expect(Keyed::using('phone', Format::keepLast(4))(null, Factory::create(), new FkRow))->toBeNull();
    });

    it('makes signatures differ by parameters', function () {
        $four = Keyed::using('n', Format::keepLast(4))->signature();

        expect($four)->not->toBe(Keyed::using('n', Format::keepLast(3))->signature());
        expect($four)->not->toBe(Keyed::using('n', Format::keepPrefix(4))->signature());
        expect($four)->toBe(Keyed::using('n', Format::keepLast(4))->signature());
    });

    it('accepts every helper as a Keyed shape', function (RandomizedGenerator $helper) {
        expect(Keyed::using('n', $helper)->namespace())->toBe('n');
    })->with([
        fn () => Format::keepLength(),
        fn () => Format::keepPrefix(2),
        fn () => Format::keepLast(2),
        fn () => Format::keepEmailDomain(),
    ]);
}
