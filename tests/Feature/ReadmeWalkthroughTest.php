<?php

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    class User extends Model
    {
        protected $table = 'users';

        protected $guarded = [];
    }
}

namespace App\Sanitizers {
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class UserSanitizer extends Sanitizer
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

    use App\Models\User;
    use App\Sanitizers\UserSanitizer;
    use Illuminate\Support\Facades\Artisan;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    beforeEach(function () {
        if (file_exists(config_path('pii.php'))) {
            @unlink(config_path('pii.php'));
        }
    });

    afterEach(function () {
        @unlink(config_path('pii.php'));
    });

    it('validates AC-15: following only the README, install -> publish -> define one Sanitizer -> register the model -> --dry-run succeeds', function () {
        // Step 1 (README "Installation"): publish the config file, exactly
        // the documented command.
        Artisan::call('vendor:publish', ['--tag' => 'pii-config', '--force' => true]);
        expect(file_exists(config_path('pii.php')))->toBeTrue();

        // Step 2 (README "Defining a Sanitizer"): the App\Sanitizers\UserSanitizer
        // class above is the exact class from the README's own code sample,
        // resolved for App\Models\User purely by the App\Sanitizers\{Model}Sanitizer
        // convention — no pii.sanitizers entry, matching "Resolution is by convention".
        expect(class_exists(UserSanitizer::class))->toBeTrue();

        // Step 3 (README "Register the model"): pii.models defaults to []
        // and has no auto-discovery, so the model must be listed explicitly.
        config()->set('pii.models', [User::class]);

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });

        DB::table('users')->insert([
            'name' => 'Seed Name',
            'email' => 'seed@example.com',
        ]);

        // Step 4 (README "Running the command"): the exact command the
        // README tells a first-time developer to run first, every time.
        $this->artisan('pii:sanitize', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dry run — no data will be written.')
            ->run();

        // --dry-run's own guarantee: nothing was written.
        $row = DB::table('users')->first();
        expect($row->name)->toBe('Seed Name');
        expect($row->email)->toBe('seed@example.com');
    });
}
