<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class RsrUser extends Model
    {
        protected $table = 'rsr_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RsrUserSanitizer extends Sanitizer
    {
        /** @var array<string, mixed> */
        public static array $fields = [];

        /** @var list<string> */
        public static array $categorical = [];

        public function fields(): array
        {
            return static::$fields;
        }

        public function categorical(): array
        {
            return static::$categorical;
        }
    }

    function rsrKeyed(): Keyed
    {
        return Keyed::pattern('rsr-name', '????????');
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\DistributionSampler;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\ConstraintViolationException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\RunReport;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\UniqueValueTracker;
    use Shahirul22\LaravelPiiSanitizer\Values\Format;
    use Shahirul22\LaravelPiiSanitizer\Values\Format\Pattern;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
    use Shahirul22\LaravelPiiSanitizer\Values\KeyedResolver;
    use Shahirul22\LaravelPiiSanitizer\Values\KeyedValueRegistry;

    function rsrCreateUsers(): void
    {
        Schema::create('rsr_users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('tier')->nullable();
        });

        DB::table('rsr_users')->insert([
            ['email' => 'a@real.test', 'name' => 'Alice', 'tier' => 'gold'],
            ['email' => 'b@real.test', 'name' => 'Bob', 'tier' => 'silver'],
        ]);
    }

    function rsrRun(bool $dryRun = false): RunReport
    {
        return app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: $dryRun));
    }

    function rsrProperty(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x04", 32));
        config()->set('pii.sanitizers', [RsrUser::class => RsrUserSanitizer::class]);
        config()->set('pii.models', [RsrUser::class]);

        RsrUserSanitizer::$fields = ['name' => 'name'];
        RsrUserSanitizer::$categorical = [];

        rsrCreateUsers();
    });

    // BUG-11: schema metadata cached by the container singletons must not
    // survive from one run to the next in the same process.

    it('sees a foreign key added between two runs in one process', function () {
        RsrUserSanitizer::$fields = ['email' => 'safeEmail'];

        expect(rsrRun(dryRun: true)->failed())->toBeFalse();

        Schema::create('rsr_orders', function ($table) {
            $table->id();
            $table->string('user_email')->nullable();
            $table->foreign('user_email')->references('email')->on('rsr_users');
        });

        expect(fn () => rsrRun(dryRun: true))->toThrow(UnsafeColumnException::class, 'rsr_orders');
    });

    it('sees a column added between two runs in one process', function () {
        expect(rsrRun(dryRun: true)->failed())->toBeFalse();

        Schema::table('rsr_users', function ($table) {
            $table->string('phone')->nullable();
        });

        RsrUserSanitizer::$fields = ['phone' => 'phoneNumber'];

        expect(rsrRun(dryRun: true)->failed())->toBeFalse();
    });

    it('sees a unique index added between two runs in one process', function () {
        RsrUserSanitizer::$fields = ['tier' => 'word'];
        RsrUserSanitizer::$categorical = ['tier'];

        expect(rsrRun(dryRun: true)->failed())->toBeFalse();

        Schema::table('rsr_users', function ($table) {
            $table->unique('tier');
        });

        expect(fn () => rsrRun(dryRun: true))->toThrow(InvalidCategoricalColumnException::class, 'unique constraint');
    });

    it('sees a column constraint changed between two runs in one process', function () {
        RsrUserSanitizer::$fields = ['tier' => 'platinum'];

        expect(rsrRun(dryRun: true)->failed())->toBeFalse();

        Schema::drop('rsr_users');
        Schema::create('rsr_users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->enum('tier', ['gold', 'silver'])->nullable();
        });

        expect(fn () => rsrRun(dryRun: true))->toThrow(ConstraintViolationException::class, 'tier');
    });

    // BUG-27 (absorbs BUG-30): run-scoped state that holds data is cleared at
    // the start of a run and again when it ends.

    it('clears a key cached before the run, so the run uses the configured key', function () {
        RsrUserSanitizer::$fields = ['name' => rsrKeyed()];

        app(KeyedValueRegistry::class)->key();

        $newKey = str_repeat("\x05", 32);
        config()->set('pii.keyed.key', $newKey);

        expect(rsrRun()->failed())->toBeFalse();

        $names = DB::table('rsr_users')->orderBy('id')->pluck('name')->all();

        expect($names[0])->toBe(KeyedResolver::candidate($newKey, 'rsr-name', 'Alice', 0, new Pattern('????????'), 'Alice'));
        expect($names[1])->toBe(KeyedResolver::candidate($newKey, 'rsr-name', 'Bob', 0, new Pattern('????????'), 'Bob'));
    });

    it('retains neither the key nor any original value once the run ends', function (bool $escapes) {
        RsrUserSanitizer::$fields = [
            'email' => Keyed::using('rsr-email', Format::keepEmailDomain()),
            'name' => 'name',
            'tier' => 'word',
        ];
        RsrUserSanitizer::$categorical = ['tier'];

        $options = new RunOptions(chunkSize: 10, onProgress: $escapes ? function ($event) {
            if ($event->chunk !== null) {
                throw new LogicException('stop');
            }
        } : null);

        try {
            $report = app(SanitizationRunner::class)->run($options);
            expect($escapes)->toBeFalse();
            expect($report->failed())->toBeFalse();
        } catch (LogicException $e) {
            expect($escapes)->toBeTrue();
        }

        $registry = app(KeyedValueRegistry::class);

        expect(rsrProperty($registry, 'key'))->toBeNull();
        expect(rsrProperty($registry, 'forbidden'))->toBe([]);
        expect(rsrProperty($registry, 'assigned'))->toBe([]);
        expect(rsrProperty($registry, 'owners'))->toBe([]);
        expect(rsrProperty($registry, 'bindings'))->toBe([]);
        expect(rsrProperty(app(UniqueValueTracker::class), 'taken'))->toBe([]);
        expect(rsrProperty(app(DistributionSampler::class), 'profileCache'))->toBe([]);
    })->with([
        'completed run' => [false],
        'exception escaping the run' => [true],
    ]);
}
