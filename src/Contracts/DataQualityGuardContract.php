<?php

namespace Shahirul22\LaravelPiiSanitizer\Contracts;

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
use Shahirul22\LaravelPiiSanitizer\Sanitizer;

interface DataQualityGuardContract
{
    /**
     * @throws InvalidCategoricalColumnException
     */
    public function assertValid(Sanitizer $sanitizer, Model|string $model): void;
}
