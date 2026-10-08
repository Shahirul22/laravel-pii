<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

use Random\Randomizer;
use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

/**
 * A structurally valid Malaysian NRIC: YYMMDD + PB + ### + G, or YYMMDD-PB-###G
 * when hyphenated. The input value is ignored.
 *
 * "Structurally valid" means: the requested hyphenation, a real calendar date
 * (century inferred from the pinned 1940-01-01..2010-12-31 range, so YY >= 40 is
 * 19YY), a place-of-birth code in the pinned set, and the requested gender
 * parity (odd male, even female) on the last digit. There is no checksum.
 * Faker's ms_MY myKadNumber() is not used: it draws the day independently of
 * the month and can emit impossible dates.
 *
 * Draw order is part of the keyed derivation contract and must not change, as
 * it changes every keyed output: (1) day offset, (2) place-of-birth index,
 * (3) serial, (4) gender digit.
 *
 * Assumption, not verified against a JPN source: place-of-birth set 01-16 and
 * 21-59, birthdate range 1940-01-01..2010-12-31 (design spec Open items).
 */
final class Nric extends RandomizedValue
{
    /** 1940-01-01 00:00:00 UTC. */
    public const FIRST_BIRTH_DAY = -946771200;

    /** Days from 1940-01-01 to 2010-12-31 inclusive. */
    public const BIRTH_DAY_COUNT = 25933;

    public const PLACE_OF_BIRTH_CODES = [
        '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16',
        '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32', '33', '34', '35', '36',
        '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '48', '49', '50', '51', '52',
        '53', '54', '55', '56', '57', '58', '59',
    ];

    /**
     * @throws \InvalidArgumentException when $gender is not null, "male" or "female"
     */
    public function __construct(private readonly bool $hyphen = false, private readonly ?string $gender = null)
    {
        if ($gender !== null && $gender !== 'male' && $gender !== 'female') {
            throw new \InvalidArgumentException('[laravel-pii-sanitizer] Malaysia::nric() gender must be "male", "female" or null.');
        }
    }

    public function generate(mixed $value, Randomizer $random): string
    {
        $date = gmdate('ymd', self::FIRST_BIRTH_DAY + $random->getInt(0, self::BIRTH_DAY_COUNT - 1) * 86400);
        $pb = self::PLACE_OF_BIRTH_CODES[$random->getInt(0, count(self::PLACE_OF_BIRTH_CODES) - 1)];
        $serial = sprintf('%03d', $random->getInt(0, 999));
        $digit = match ($this->gender) {
            'male' => 2 * $random->getInt(0, 4) + 1,
            'female' => 2 * $random->getInt(0, 4),
            default => $random->getInt(0, 9),
        };

        return $this->hyphen
            ? $date.'-'.$pb.'-'.$serial.$digit
            : $date.$pb.$serial.$digit;
    }

    public function signature(): string
    {
        return 'nric:'.($this->hyphen ? 'hyphen' : 'plain').':'.($this->gender ?? 'any');
    }
}
