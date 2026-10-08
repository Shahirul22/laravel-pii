<?php

namespace Shahirul22\LaravelPiiSanitizer\Values\Format;

use Random\Randomizer;
use Shahirul22\LaravelPiiSanitizer\Values\RandomizedValue;

/**
 * A fixed-shape value built from a pattern: "#" is a digit 0-9, "?" an
 * uppercase letter A-Z, "*" an uppercase alphanumeric, "\" escapes the next
 * character as a literal, and every other character is a literal.
 *
 * Exactly one Randomizer::getInt() call is made per placeholder, left to
 * right; literals consume nothing. That draw order is part of the keyed
 * derivation contract (see the known-answer test), so do not change it.
 * The input value is ignored.
 */
final class Pattern extends RandomizedValue
{
    public const ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** @var list<array{0: string, 1: string}> */
    private array $tokens = [];

    /**
     * @throws \InvalidArgumentException when the pattern has no placeholder or ends in a dangling escape
     */
    public function __construct(private readonly string $pattern)
    {
        $characters = mb_str_split($pattern, 1, 'UTF-8');
        $count = count($characters);
        $placeholders = 0;

        for ($i = 0; $i < $count; $i++) {
            $character = $characters[$i];

            if ($character === '\\') {
                if ($i + 1 >= $count) {
                    throw new \InvalidArgumentException('[laravel-pii-sanitizer] A pattern cannot end in a dangling "\\" escape.');
                }

                $this->tokens[] = ['literal', $characters[++$i]];

                continue;
            }

            if ($character === '#' || $character === '?' || $character === '*') {
                $this->tokens[] = [$character, $character];
                $placeholders++;

                continue;
            }

            $this->tokens[] = ['literal', $character];
        }

        if ($placeholders === 0) {
            throw new \InvalidArgumentException('[laravel-pii-sanitizer] A pattern needs at least one placeholder (#, ? or *); a constant has a one-value domain.');
        }
    }

    public function generate(mixed $value, Randomizer $random): string
    {
        $output = '';

        foreach ($this->tokens as [$kind, $text]) {
            $output .= match ($kind) {
                '#' => (string) $random->getInt(0, 9),
                '?' => self::ALPHA[$random->getInt(0, 25)],
                '*' => self::ALNUM[$random->getInt(0, 35)],
                default => $text,
            };
        }

        return $output;
    }

    public function signature(): string
    {
        return 'pattern:'.$this->pattern;
    }
}
