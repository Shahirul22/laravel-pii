<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Contracts\Foundation\Application;
use Shahirul22\LaravelPiiSanitizer\Contracts\EnvironmentGuardContract;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeEnvironmentException;

/**
 * Enforces the force/confirm decision table fixed in
 * docs/design/execution-engine-and-safety/execution-engine-spec §4. The guard
 * never prompts — confirmation arrives as an already-obtained boolean on
 * RunOptions; the interactive prompt itself belongs to Phase 6's
 * SanitizeCommand.
 */
class EnvironmentGuard implements EnvironmentGuardContract
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function assertRunnable(RunOptions $options): void
    {
        $allowed = $this->allowedEnvironments();
        $environment = (string) $this->app->environment();

        if (in_array($environment, $allowed, true)) {
            return;
        }

        if (! $options->force) {
            throw UnsafeEnvironmentException::notPermitted($environment, $allowed);
        }

        if (! $options->confirmed) {
            throw UnsafeEnvironmentException::confirmationRequired($environment, $allowed);
        }
    }

    /** @return list<string> */
    private function allowedEnvironments(): array
    {
        $configured = config('pii.environments');

        if (! is_array($configured) || $configured === []) {
            return ['local', 'testing'];
        }

        return array_values(array_map(strval(...), $configured));
    }
}
