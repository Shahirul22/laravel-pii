<?php

/**
 * Drift guard for the keyed derivation (spec R1.1, Algorithm point 5).
 *
 * A failure here means cross-run determinism broke. Tell the layers apart:
 *  - A seed-hex failure is the HMAC layer (message framing, domain tag or key
 *    handling changed).
 *  - An output or getInt-vector failure is the Randomizer layer (PHP, the
 *    Xoshiro256StarStar engine or a shape's draw order changed).
 * The remedy is a "pii-keyed/v2" derivation bump, which is a semver-major
 * change because it changes every keyed output; see the spec's Open items.
 * CI must run this file on the lowest and highest supported PHP versions.
 */

namespace {
    use Illuminate\Database\Eloquent\Model;

    class KatRow extends Model
    {
        protected $table = 'kat_rows';

        protected $guarded = [];
    }
}

namespace {

    use Faker\Factory;
    use Random\Engine\Xoshiro256StarStar;
    use Random\Randomizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Format\Pattern;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
    use Shahirul22\LaravelPiiSanitizer\Values\KeyedResolver;

    const KAT_PROBE_0_SEED = 'f0b17d2572f37573fa95cd49f7924ffe274f4f79f41488f695e03d26348bff65';
    const KAT_PROBE_0_OUTPUT = '730182-UF-N5';
    const KAT_PROBE_1_SEED = 'c571467b8e775de403f3c5f6f4835df6d6996cfc5802147eaccda611dbc08f5d';
    const KAT_PROBE_1_OUTPUT = '589684-RE-6Y';

    it('derives the frozen HMAC seeds', function () {
        $key = str_repeat("\x01", 32);

        expect(bin2hex(KeyedResolver::seed($key, 'nric', '900101015555', 0)))->toBe(KAT_PROBE_0_SEED);
        expect(bin2hex(KeyedResolver::seed($key, 'nric', '900101015555', 1)))->toBe(KAT_PROBE_1_SEED);
    });

    it('produces the frozen pattern outputs', function () {
        $key = str_repeat("\x01", 32);
        $shape = new Pattern('######-??-**');

        expect(KeyedResolver::candidate($key, 'nric', '900101015555', 0, $shape, '900101015555'))->toBe(KAT_PROBE_0_OUTPUT);
        expect(KeyedResolver::candidate($key, 'nric', '900101015555', 1, $shape, '900101015555'))->toBe(KAT_PROBE_1_OUTPUT);
    });

    it('produces the frozen output end-to-end through Keyed (unregistered namespace is pure)', function () {
        config()->set('pii.keyed.key', str_repeat("\x01", 32));

        $output = Keyed::pattern('nric', '######-??-**')('900101015555', Factory::create(), new KatRow);

        expect($output)->toBe(KAT_PROBE_0_OUTPUT);
    });

    it('pins the seeded Randomizer::getInt vector', function () {
        $random = new Randomizer(new Xoshiro256StarStar(hex2bin(str_repeat('ab', 32))));

        expect($random->getInt(0, 9))->toBe(3);
        expect($random->getInt(0, 1000000))->toBe(445367);
        expect($random->getInt(0, PHP_INT_MAX))->toBe(3255307777207173930);
    });
}
