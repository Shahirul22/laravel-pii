<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

use Random\Randomizer;
use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

/**
 * A random Malaysian bank-account number of the bank's digit length, first
 * digit non-zero. With no bank given, a bank is drawn from the table first, so
 * a bare instance yields a mix of lengths. The input value is ignored.
 *
 * No checksum: the banks do not publish one. An unknown bank is rejected at
 * construction, which surfaces in the registration pass at boot.
 *
 * Draw order is part of the keyed derivation contract and must not change:
 * (1) bank index (only when no bank is given), (2) first digit 1-9, (3) the
 * remaining digits left to right.
 *
 * Assumption, not verified against bank sources: per-bank digit lengths
 * (design spec Open items).
 */
final class BankAccount extends RandomizedValue
{
    public const LENGTHS = [
        'Maybank' => 12, 'CIMB' => 14, 'Public Bank' => 10, 'RHB' => 14,
        'Hong Leong' => 11, 'AmBank' => 13, 'Bank Islam' => 14, 'BSN' => 16,
    ];

    /**
     * @throws \InvalidArgumentException when $bank is not in the length table
     */
    public function __construct(private readonly ?string $bank = null)
    {
        if ($bank !== null && ! array_key_exists($bank, self::LENGTHS)) {
            throw new \InvalidArgumentException('[laravel-pii-sanitizer] Unknown Malaysian bank "'.$bank.'"; expected one of: '.implode(', ', array_keys(self::LENGTHS)).'.');
        }
    }

    public function generate(mixed $value, Randomizer $random): string
    {
        $bank = $this->bank ?? array_keys(self::LENGTHS)[$random->getInt(0, count(self::LENGTHS) - 1)];
        $output = (string) $random->getInt(1, 9);

        for ($i = 1; $i < self::LENGTHS[$bank]; $i++) {
            $output .= (string) $random->getInt(0, 9);
        }

        return $output;
    }

    public function signature(): string
    {
        return 'bankAccount:'.($this->bank ?? 'any');
    }
}
