<?php

use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shahirul22\LaravelPiiSanitizer\DistributionSampler;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;

function dsSampler(): DistributionSampler
{
    return new DistributionSampler(app(DatabaseManager::class));
}

function dsFaker(): Generator
{
    $faker = Factory::create();
    $faker->seed(1234);

    return $faker;
}

it('derives a profile of value => count for a skewed column', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 70; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'active']);
    }
    for ($i = 0; $i < 20; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'pending']);
    }
    for ($i = 0; $i < 10; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'banned']);
    }

    $profile = dsSampler()->profile('sampler_rows', 'status');

    expect($profile)->toHaveCount(3);

    $counts = [];
    foreach ($profile as $entry) {
        $counts[$entry['value']] = $entry['count'];
    }

    expect($counts)->toEqualCanonicalizing([
        'active' => 70,
        'pending' => 20,
        'banned' => 10,
    ]);
});

it('treats NULL as a legitimate category', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 5; $i++) {
        DB::table('sampler_rows')->insert(['status' => null]);
    }
    for ($i = 0; $i < 5; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'active']);
    }

    $profile = dsSampler()->profile('sampler_rows', 'status');

    expect($profile)->toHaveCount(2);

    $nullEntry = collect($profile)->first(fn ($entry) => $entry['value'] === null);

    expect($nullEntry)->not->toBeNull();
    expect($nullEntry['count'])->toBe(5);
});

it('distinguishes null, integer 1 and string "1" as separate categories', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    DB::table('sampler_rows')->insert(['status' => null]);
    DB::table('sampler_rows')->insert(['status' => '1']);

    $profile = dsSampler()->profile('sampler_rows', 'status');

    expect($profile)->toHaveCount(2);
});

it('keeps distinct invalid-UTF-8 values as separate categories instead of colliding into one bucket', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->binary('status')->nullable();
    });

    // json_encode() returns false for a string containing a malformed
    // UTF-8 byte sequence; without a guard, both would collapse into the
    // same '' array key, corrupting the profile. Two distinct invalid
    // sequences here must remain two distinct categories.
    $badA = "status-a-\xFF-end";
    $badB = "status-b-\xFE-end";

    for ($i = 0; $i < 7; $i++) {
        DB::table('sampler_rows')->insert(['status' => $badA]);
    }
    for ($i = 0; $i < 3; $i++) {
        DB::table('sampler_rows')->insert(['status' => $badB]);
    }

    $profile = dsSampler()->profile('sampler_rows', 'status');

    expect($profile)->toHaveCount(2);

    $counts = [];
    foreach ($profile as $entry) {
        $counts[$entry['value']] = $entry['count'];
    }

    expect($counts)->toEqualCanonicalizing([
        $badA => 7,
        $badB => 3,
    ]);
});

it('caches the profile for the whole run so repeated sampling issues one aggregate query', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 10; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'active']);
    }

    $sampler = dsSampler();
    $faker = dsFaker();

    DB::flushQueryLog();
    DB::enableQueryLog();

    for ($i = 0; $i < 50; $i++) {
        $sampler->sample('sampler_rows', 'status', $faker);
    }

    $queries = array_filter(DB::getQueryLog(), fn ($entry) => str_contains(strtolower($entry['query']), 'sampler_rows'));

    expect($queries)->toHaveCount(1);

    DB::table('sampler_rows')->insert(['status' => 'new-value']);

    $profileAfter = $sampler->profile('sampler_rows', 'status');

    expect($profileAfter)->toHaveCount(1);
});

it('profiles the given connection separately, not sharing a cache entry with the default connection', function () {
    config()->set('database.connections.ds_secondary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 10; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'default-only']);
    }

    Schema::connection('ds_secondary')->create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 5; $i++) {
        DB::connection('ds_secondary')->table('sampler_rows')->insert(['status' => 'secondary-only']);
    }

    $sampler = dsSampler();

    // Profile the default connection first, so a pre-fix (table+column-only
    // cache key) implementation would serve this cached entry back for the
    // secondary connection's profile() call below instead of re-querying.
    $defaultProfile = $sampler->profile('sampler_rows', 'status');
    $secondaryProfile = $sampler->profile('sampler_rows', 'status', connection: 'ds_secondary');

    $defaultValues = array_column($defaultProfile, 'value');
    $secondaryValues = array_column($secondaryProfile, 'value');

    expect($defaultValues)->toBe(['default-only']);
    expect($secondaryValues)->toBe(['secondary-only']);
});

it('clears cached profiles on reset()', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 10; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'active']);
    }

    $sampler = dsSampler();

    $sampler->profile('sampler_rows', 'status');

    DB::table('sampler_rows')->insert(['status' => 'pending']);

    $sampler->reset();

    $profile = $sampler->profile('sampler_rows', 'status');

    expect($profile)->toHaveCount(2);
});

it('samples proportionally within the AC-6 tolerance over 1000 draws', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 70; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'active']);
    }
    for ($i = 0; $i < 20; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'pending']);
    }
    for ($i = 0; $i < 10; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'banned']);
    }

    $sampler = dsSampler();
    $faker = dsFaker();

    $draws = [];
    for ($i = 0; $i < 1000; $i++) {
        $draws[] = $sampler->sample('sampler_rows', 'status', $faker);
    }

    $counts = array_count_values($draws);
    $expected = ['active' => 0.70, 'pending' => 0.20, 'banned' => 0.10];

    foreach ($expected as $value => $p) {
        $observed = ($counts[$value] ?? 0) / 1000;
        expect(abs($observed - $p))->toBeLessThanOrEqual(0.05);
    }
});

it('never returns a value outside the original value set', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 70; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'active']);
    }
    for ($i = 0; $i < 20; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'pending']);
    }
    for ($i = 0; $i < 10; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'banned']);
    }

    $sampler = dsSampler();
    $faker = dsFaker();

    for ($i = 0; $i < 1000; $i++) {
        expect($sampler->sample('sampler_rows', 'status', $faker))->toBeIn(['active', 'pending', 'banned']);
    }
});

it('always returns the sole value for a single-category column', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 40; $i++) {
        DB::table('sampler_rows')->insert(['status' => 'active']);
    }

    $sampler = dsSampler();
    $faker = dsFaker();

    for ($i = 0; $i < 200; $i++) {
        expect($sampler->sample('sampler_rows', 'status', $faker))->toBe('active');
    }
});

it('always returns null for an all-NULL column', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    for ($i = 0; $i < 30; $i++) {
        DB::table('sampler_rows')->insert(['status' => null]);
    }

    $sampler = dsSampler();
    $faker = dsFaker();

    for ($i = 0; $i < 100; $i++) {
        expect($sampler->sample('sampler_rows', 'status', $faker))->toBeNull();
    }
});

it('returns an empty profile for an empty table', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    $profile = dsSampler()->profile('sampler_rows', 'status');

    expect($profile)->toBe([]);
});

it('rejects a column with more distinct values than MAX_CATEGORIES', function () {
    Schema::create('sampler_rows', function ($table) {
        $table->id();
        $table->string('status')->nullable();
    });

    $rows = [];
    for ($i = 0; $i < DistributionSampler::MAX_CATEGORIES + 1; $i++) {
        $rows[] = ['status' => "value-{$i}"];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('sampler_rows')->insert($chunk);
    }

    try {
        dsSampler()->profile('sampler_rows', 'status');

        test()->fail('Expected InvalidCategoricalColumnException to be thrown.');
    } catch (InvalidCategoricalColumnException $exception) {
        expect($exception->getMessage())->toContain((string) (DistributionSampler::MAX_CATEGORIES + 1));
        expect($exception->getMessage())->toContain('limit 1000');
    }
});
