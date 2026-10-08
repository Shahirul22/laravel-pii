<?php

namespace Shahirul22\LaravelPiiSanitizer\Values;

use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\BankAccount;
use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\Nric;
use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\Sst;
use Shahirul22\LaravelPiiSanitizer\Values\Malaysia\State;

/**
 * Malaysia locale generators: structurally valid NRIC, SST registration number,
 * state name and bank-account number.
 *
 *     'nric'  => Malaysia::nric(),
 *     'nric'  => Keyed::using('nric', Malaysia::nric()),
 *     'state' => 'malaysiaState',
 *
 * They carry their own data tables and draw only from the passed Randomizer, so
 * they are independent of app.faker_locale and of Faker's ms_MY provider data.
 * Used bare, an instance is random-mode (reproducible under a seeded Faker);
 * wrapped in Keyed it is deterministic. Constructors stay cheap because
 * Sanitizer::fields() is evaluated per row. The 'malaysia*' shorthand names are a
 * container-Faker feature (see Malaysia\MalaysiaProvider); the instance form
 * works everywhere.
 *
 * See docs/design/value-generation-primitives/spec, "R4 - Malaysia locale generators".
 */
final class Malaysia
{
    private function __construct() {}

    /**
     * @throws \InvalidArgumentException when $gender is not null, "male" or "female"
     */
    public static function nric(bool $hyphen = false, ?string $gender = null): Nric
    {
        return new Nric($hyphen, $gender);
    }

    public static function sst(): Sst
    {
        return new Sst;
    }

    public static function state(): State
    {
        return new State;
    }

    /**
     * @throws \InvalidArgumentException when $bank is not a known bank
     */
    public static function bankAccount(?string $bank = null): BankAccount
    {
        return new BankAccount($bank);
    }
}
