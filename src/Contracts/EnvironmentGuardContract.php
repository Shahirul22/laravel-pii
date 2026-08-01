<?php

namespace Shahirul22\LaravelPiiSanitizer\Contracts;

use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeEnvironmentException;
use Shahirul22\LaravelPiiSanitizer\RunOptions;

/** Fixes the force/confirm contract of execution-engine-spec §4. */
interface EnvironmentGuardContract
{
    /** @throws UnsafeEnvironmentException */
    public function assertRunnable(RunOptions $options): void;
}
