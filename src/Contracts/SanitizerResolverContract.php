<?php

namespace Shahirul22\LaravelPiiSanitizer\Contracts;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Sanitizer;

interface SanitizerResolverContract
{
    public function resolve(Model|string $model): ?Sanitizer;
}
