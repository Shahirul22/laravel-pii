<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Format;

use Random\Randomizer;

/**
 * Replaces the local part of an email address by class, keeping its length
 * and separators, and keeps the "@" and domain byte-exact. The split is at the
 * last "@". With no "@", or an empty local part or domain, the value is
 * treated as keepLength() and nothing is preserved.
 */
final class KeepEmailDomain extends FormatPreservingValue
{
    protected function transform(array $characters, Randomizer $random): string
    {
        $at = null;

        for ($i = count($characters) - 1; $i >= 0; $i--) {
            if ($characters[$i] === '@') {
                $at = $i;
                break;
            }
        }

        if ($at === null || $at === 0 || $at === count($characters) - 1) {
            return self::replace($characters, $random);
        }

        return self::replace(array_slice($characters, 0, $at), $random)
            .implode('', array_slice($characters, $at));
    }

    public function signature(): string
    {
        return 'keepEmailDomain';
    }
}
