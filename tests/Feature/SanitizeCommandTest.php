<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class CmdUser extends Model
    {
        protected $table = 'cmd_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class CmdUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => 'name',
                'email' => 'safeEmail',
            ];
        }
    }

    class CmdOrder extends Model
    {
        protected $table = 'cmd_orders';

        protected $guarded = [];

        public $timestamps = false;
    }

    class CmdOrderSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'customer_name' => 'name',
            ];
        }
    }
}

namespace {

    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Output\BufferedOutput;

    beforeEach(function () {
        Schema::create('cmd_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });

        for ($i = 0; $i < 25; $i++) {
            DB::table('cmd_users')->insert([
                'name' => "Seed Name {$i}",
                'email' => "seed-{$i}@example.com",
            ]);
        }

        config()->set('pii.sanitizers', [
            CmdUser::class => CmdUserSanitizer::class,
        ]);
        config()->set('pii.models', [CmdUser::class]);
    });

    afterEach(function () {
        app()->detectEnvironment(fn () => 'testing');
    });

    function cmdUsersSnapshot()
    {
        return DB::table('cmd_users')->orderBy('id')->get();
    }

    function cmdOrdersSnapshot()
    {
        return DB::table('cmd_orders')->orderBy('id')->get();
    }

    it('runs a normal sanitize and exits 0', function () {
        $this->artisan('pii:sanitize', ['--chunk' => 10])->assertExitCode(0)->run();

        $after = cmdUsersSnapshot();

        foreach ($after as $row) {
            expect($row->name)->not->toStartWith('Seed Name');
            expect($row->email)->not->toStartWith('seed-');
        }
    });

    it('honors --dry-run by writing nothing', function () {
        $before = cmdUsersSnapshot();

        $this->artisan('pii:sanitize', ['--dry-run' => true, '--chunk' => 10])->assertExitCode(0)->run();

        $after = cmdUsersSnapshot();

        expect($after)->toEqual($before);
    });

    it('honors --chunk by using the given chunk size', function () {
        $this->artisan('pii:sanitize', ['--chunk' => 5])
            ->assertExitCode(0)
            ->expectsOutputToContain('5/5 chunks')
            ->run();
    });

    it('honors --model to select a single model', function () {
        Schema::create('cmd_orders', function ($table) {
            $table->id();
            $table->string('customer_name');
        });

        DB::table('cmd_orders')->insert(['customer_name' => 'Seed Customer']);

        config()->set('pii.sanitizers', [
            CmdUser::class => CmdUserSanitizer::class,
            CmdOrder::class => CmdOrderSanitizer::class,
        ]);
        config()->set('pii.models', [CmdUser::class, CmdOrder::class]);

        $before = cmdUsersSnapshot();

        $this->artisan('pii:sanitize', ['--model' => [CmdOrder::class], '--chunk' => 10])
            ->assertExitCode(0)
            ->run();

        $afterUsers = cmdUsersSnapshot();
        $afterOrders = cmdOrdersSnapshot();

        expect($afterUsers)->toEqual($before);
        expect($afterOrders[0]->customer_name)->not->toBe('Seed Customer');
    });

    it('falls back to config pii.models when --model is absent', function () {
        Schema::create('cmd_orders', function ($table) {
            $table->id();
            $table->string('customer_name');
        });

        DB::table('cmd_orders')->insert(['customer_name' => 'Seed Customer']);

        config()->set('pii.sanitizers', [
            CmdUser::class => CmdUserSanitizer::class,
            CmdOrder::class => CmdOrderSanitizer::class,
        ]);
        config()->set('pii.models', [CmdUser::class, CmdOrder::class]);

        $this->artisan('pii:sanitize', ['--chunk' => 10])->assertExitCode(0)->run();

        $afterUsers = cmdUsersSnapshot();
        $afterOrders = cmdOrdersSnapshot();

        foreach ($afterUsers as $row) {
            expect($row->name)->not->toStartWith('Seed Name');
        }

        expect($afterOrders[0]->customer_name)->not->toBe('Seed Customer');
    });

    it('refuses to run outside the allowed environments without --force', function () {
        $before = cmdUsersSnapshot();

        app()->detectEnvironment(fn () => 'production');

        $this->artisan('pii:sanitize')
            ->assertExitCode(1)
            ->expectsOutputToContain('Refusing to run')
            ->run();

        $after = cmdUsersSnapshot();

        expect($after)->toEqual($before);
    });

    it('proceeds outside the allowed environments with --force and an accepted confirmation', function () {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('pii:sanitize', ['--force' => true, '--chunk' => 10])
            ->expectsConfirmation(
                'You are about to sanitize PII in the "production" environment. This is a development-time tool and this environment is not in your configured allow-list. Continue?',
                'yes'
            )
            ->assertExitCode(0)
            ->run();

        $after = cmdUsersSnapshot();

        foreach ($after as $row) {
            expect($row->name)->not->toStartWith('Seed Name');
        }
    });

    it('refuses when --force is given but the confirmation is declined', function () {
        $before = cmdUsersSnapshot();

        app()->detectEnvironment(fn () => 'production');

        $this->artisan('pii:sanitize', ['--force' => true, '--chunk' => 10])
            ->expectsConfirmation(
                'You are about to sanitize PII in the "production" environment. This is a development-time tool and this environment is not in your configured allow-list. Continue?',
                'no'
            )
            ->assertExitCode(1)
            ->expectsOutputToContain('was not confirmed')
            ->run();

        $after = cmdUsersSnapshot();

        expect($after)->toEqual($before);
    });

    it('does not prompt when the environment is allowed', function () {
        $this->artisan('pii:sanitize', ['--force' => true, '--chunk' => 10])
            ->assertExitCode(0)
            ->run();
    });

    it('does not prompt when the environment is disallowed and --force was not passed', function () {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('pii:sanitize')
            ->assertExitCode(1)
            ->run();
    });

    it('rejects a non-numeric --chunk before touching the database', function () {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->artisan('pii:sanitize', ['--chunk' => 'abc'])
            ->assertExitCode(1)
            ->expectsOutputToContain('The --chunk option must be a positive integer, got "abc".')
            ->run();

        $hitTable = collect(DB::getQueryLog())
            ->contains(fn (array $entry): bool => str_contains($entry['query'], 'cmd_users'));

        expect($hitTable)->toBeFalse();
    });

    it('resolves the confirmation prompt to false under --no-interaction, matching the README\'s documented default-to-false behavior', function () {
        // Testbench's artisan() test double mocks OutputStyle::askQuestion()
        // itself, which is exactly where Symfony's own isInteractive() check
        // lives (QuestionHelper::ask()) — so that harness cannot exercise the
        // real short-circuit. Run the command through Symfony Console's own
        // Application::run() instead, with real (buffered) I/O and
        // --no-interaction set on the real input, the same mechanism the
        // artisan binary sets at runtime.
        app()->detectEnvironment(fn () => 'production');

        $kernel = app(Kernel::class);

        $input = new ArrayInput([
            'command' => 'pii:sanitize',
            '--force' => true,
            '--chunk' => 10,
            '--no-interaction' => true,
        ]);
        $output = new BufferedOutput;

        $exitCode = $kernel->handle($input, $output);

        expect($exitCode)->toBe(1);
        expect($output->fetch())->toContain('was not confirmed');
    });

    it('rejects a --chunk below 1', function () {
        $this->artisan('pii:sanitize', ['--chunk' => '0'])
            ->assertExitCode(1)
            ->expectsOutputToContain('The --chunk option must be a positive integer, got "0".')
            ->run();

        $this->artisan('pii:sanitize', ['--chunk' => '-3'])
            ->assertExitCode(1)
            ->expectsOutputToContain('The --chunk option must be a positive integer, got "-3".')
            ->run();
    });

    it('reports a named error instead of crashing when pii.models is not an array', function () {
        config()->set('pii.models', 'not-an-array');

        $before = cmdUsersSnapshot();

        $this->artisan('pii:sanitize')
            ->assertExitCode(1)
            ->expectsOutputToContain('pii.models')
            ->run();

        $after = cmdUsersSnapshot();

        expect($after)->toEqual($before);
    });
}
