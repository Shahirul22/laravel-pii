<?php

namespace Shahirul22\LaravelPiiSanitizer\Contracts;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
use Shahirul22\LaravelPiiSanitizer\Sanitizer;

interface SanitizerResolverContract
{
    /**
     * @throws UnsafeColumnException
     */
    public function resolve(Model|string $model): ?Sanitizer;

    /**
     * Resolve the sanitizer for a model-less table target (config
     * `pii.tables` only — no convention lookup), or null when the table has
     * no configured sanitizer.
     *
     * @throws UnsafeColumnException
     */
    public function resolveTable(string $table): ?Sanitizer;
}
