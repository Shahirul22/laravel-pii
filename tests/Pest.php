<?php

use Shahirul22\LaravelPiiSanitizer\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Faker\Generator::__destruct() calls seed() with no argument, which re-seeds
// PHP's global mt_rand generator randomly. If the cycle collector destroys a
// Faker left over from an earlier test while a test is between $faker->seed(n)
// and its draws, the seeded sequence changes and the test fails intermittently.
// Disabling the collector makes destructors run only when a reference count
// drops, so a seeded window cannot be reseeded behind the test's back.
gc_disable();
