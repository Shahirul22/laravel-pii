<?php

namespace Shahirul22\LaravelPiiSanitizer\Values;

use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;

/**
 * Loads the secret key behind Keyed generation. The only reader of
 * pii.keyed.key. The key is never logged and never placed in a message: a
 * failure names the env var and the minimum length only.
 *
 * See docs/design/value-generation-primitives/spec, "Key source and provenance".
 */
final class KeyedKey
{
    public const MIN_BYTES = 32;

    /**
     * The raw key bytes: the configured value as-is, or decoded when it is
     * "base64:"-prefixed.
     *
     * @throws InvalidConfigurationException when the key is missing, undecodable or too short
     */
    public static function fromConfig(): string
    {
        $raw = config('pii.keyed.key');

        if (! is_string($raw) || $raw === '') {
            throw InvalidConfigurationException::missingKeyedKey();
        }

        $bytes = $raw;

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);

            if ($decoded === false) {
                throw InvalidConfigurationException::missingKeyedKey();
            }

            $bytes = $decoded;
        }

        if (strlen($bytes) < self::MIN_BYTES) {
            throw InvalidConfigurationException::missingKeyedKey();
        }

        return $bytes;
    }
}
