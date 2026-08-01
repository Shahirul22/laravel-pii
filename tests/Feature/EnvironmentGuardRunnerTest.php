<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class GuardedUser extends Model
    {
        protected $table = 'guarded_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class GuardedUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => 'name',
                'email' => 'safeEmail',
            ];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeEnvironmentException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        Schema::create('guarded_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });

        for ($i = 0; $i < 10; $i++) {
            DB::table('guarded_users')->insert([
                'name' => "Seed Name {$i}",
                'email' => "seed-{$i}@example.com",
            ]);
        }

        config()->set('pii.sanitizers', [
            GuardedUser::class => GuardedUserSanitizer::class,
        ]);
        config()->set('pii.models', [GuardedUser::class]);
    });

    afterEach(function () {
        app()->detectEnvironment(fn () => 'testing');
    });

    function guardedUsersSnapshot()
    {
        return DB::table('guarded_users')->orderBy('id')->get();
    }

    it('refuses to run outside the allowed environments and modifies no row', function () {
        $before = guardedUsersSnapshot();

        app()->detectEnvironment(fn () => 'production');

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5)))
            ->toThrow(UnsafeEnvironmentException::class);

        $after = guardedUsersSnapshot();

        expect($after)->toEqual($before);
    });

    it('issues no query at all when the guard refuses', function () {
        app()->detectEnvironment(fn () => 'production');

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5));
        } catch (UnsafeEnvironmentException $e) {
            // expected
        }

        $hitTable = collect(DB::getQueryLog())
            ->contains(fn (array $entry): bool => str_contains($entry['query'], 'guarded_users'));

        expect($hitTable)->toBeFalse();
    });

    it('proceeds when force and confirmation are both given outside the allowed environments', function () {
        app()->detectEnvironment(fn () => 'production');

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5, force: true, confirmed: true));

        expect($report->failed())->toBeFalse();
        expect($report->rowsSanitized())->toBe(10);
    });

    it('still refuses a forced run that was not confirmed', function () {
        $before = guardedUsersSnapshot();

        app()->detectEnvironment(fn () => 'production');

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5, force: true)))
            ->toThrow(UnsafeEnvironmentException::class);

        $after = guardedUsersSnapshot();

        expect($after)->toEqual($before);
    });
}
