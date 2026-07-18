<?php

namespace Shahirul22\LaravelPiiSanitizer\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Shahirul22\LaravelPiiSanitizer\PiiSanitizerServiceProvider;

class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PiiSanitizerServiceProvider::class,
        ];
    }
}
