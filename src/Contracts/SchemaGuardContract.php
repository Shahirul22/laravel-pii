<?php

namespace Shahirul22\LaravelPiiSanitizer\Contracts;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
use Shahirul22\LaravelPiiSanitizer\Sanitizer;

interface SchemaGuardContract
{
    /**
     * @throws UnsafeColumnException
     */
    public function assertSafe(Sanitizer $sanitizer, Model|string $model): void;
}
