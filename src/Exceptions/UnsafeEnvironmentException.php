<?php

namespace Shahirul22\LaravelPiiSanitizer\Exceptions;

final class UnsafeEnvironmentException extends \RuntimeException
{
    /** @param  list<string>  $allowed */
    public static function notPermitted(string $environment, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            "[laravel-pii-sanitizer] Refusing to run: the application environment is \"{$environment}\". This is a development-time tool and only runs in [{$list}]. Pass --force together with an explicit confirmation to override."
        );
    }

    /** @param  list<string>  $allowed */
    public static function confirmationRequired(string $environment, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            "[laravel-pii-sanitizer] Refusing to run: --force was given in environment \"{$environment}\" but the run was not confirmed. Confirmation is required before sanitizing outside [{$list}]."
        );
    }
}
