<?php

namespace {
    use Faker\Generator;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Format;
    use Shahirul22\LaravelPiiSanitizer\Values\Json;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

    class JcDoc extends Model
    {
        protected $table = 'jc_docs';

        protected $guarded = [];

        public $timestamps = false;
    }

    class JcCastDoc extends Model
    {
        protected $table = 'jc_docs';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['settings' => 'array'];
        }
    }

    class JcPerson extends Model
    {
        protected $table = 'jc_people';

        protected $guarded = [];

        public $timestamps = false;
    }

    class JcUniquePerson extends Model
    {
        protected $table = 'jc_unique_people';

        protected $guarded = [];

        public $timestamps = false;
    }

    enum JcTier: string
    {
        case Gold = 'gold';
    }

    class JcLog
    {
        /** @var list<array{0: mixed, 1: mixed, 2: mixed}> */
        public static array $calls = [];
    }

    class JcInvokable implements ValueGenerator
    {
        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            return 'INV:'.$value;
        }
    }

    class JcPrefixer implements ValueGenerator
    {
        public function __construct(private readonly string $prefix) {}

        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            return $this->prefix.$value;
        }
    }

    class JcMatrixSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['doc' => Json::paths([
                'static->string' => 'REDACTED-STATIC',
                'static->int' => 42,
                'static->array' => ['masked' => true],
                'static->null' => null,
                'static->enum' => JcTier::Gold,
                'closure' => function ($leaf, $faker, $row) {
                    JcLog::$calls[] = [$leaf, $faker, $row];

                    return 'C:'.strrev($leaf);
                },
                'invokable' => JcInvokable::class,
                'faker' => 'safeEmail',
                'generator' => new JcPrefixer('GEN:'),
                'keyed' => Keyed::pattern('jc-code', 'K-####'),
                'format' => Format::keepLast(4),
                'malaysia' => Malaysia::nric(),
                'malaysia_shorthand' => 'malaysiaNric',
                'keyed_format' => Keyed::using('jc-phone', Format::keepLast(4)),
                'keyed_malaysia' => Keyed::using('jc-nric', Malaysia::nric()),
            ])];
        }
    }

    class JcCastSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['settings' => Json::paths([
                'contact->nric' => Keyed::using('jc-nric', Malaysia::nric()),
                'contact->phone' => Format::keepLast(4),
                'tier' => JcTier::Gold,
            ])];
        }
    }

    class JcPersonSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'nric' => Keyed::using('nric', Malaysia::nric()),
                'profile' => Json::paths(['ids->nric' => Keyed::using('nric', Malaysia::nric())]),
            ];
        }
    }

    class JcUniquePersonSanitizer extends JcPersonSanitizer {}

    class JcFloatSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['doc' => Json::paths(['score' => Keyed::pattern('jc-score', '###')])];
        }
    }

    class JcKeyOnlyPathSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['doc' => Json::paths(['keyed' => Keyed::pattern('jc-code', 'K-####')])];
        }
    }

    class JcShapeMismatchSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'nric' => Keyed::pattern('nric', '######'),
                'profile' => Json::paths(['ids->nric' => Keyed::using('nric', Malaysia::nric())]),
            ];
        }
    }

    class JcStaticObjectSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['doc' => Json::paths(['static->string' => new stdClass])];
        }
    }

    class JcStaticInfSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['doc' => Json::paths(['static->int' => INF])];
        }
    }

    class JcStaticNestedSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['doc' => Json::paths(['static->array' => ['ok' => 1, 'bad' => new ArrayObject]])];
        }
    }

    class JcCategoricalSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['doc' => Json::paths(['static->string' => 'X'])];
        }

        public function categorical(): array
        {
            return ['doc'];
        }
    }
}

namespace {
    use Faker\Generator;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\RunReport;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\Tests\Support\MalaysiaFormats;
    use Shahirul22\LaravelPiiSanitizer\Values\Format;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
    use Shahirul22\LaravelPiiSanitizer\Values\KeyedResolver;
    use Shahirul22\LaravelPiiSanitizer\Values\KeyedValueRegistry;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

    const JC_KEY = "\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01";

    const JC_DOC = '{"static":{"string":"s-orig","int":"i-orig","array":"a-orig","null":"n-orig","enum":"e-orig"},"closure":"0123456789","invokable":"inv-orig","faker":"f-orig","generator":"g-orig","keyed":"A-1234","format":"0123456789","malaysia":"900101015555","malaysia_shorthand":"900101015555","keyed_format":"0198765432","keyed_malaysia":"880202026666","keep":{"x":1,"y":[true,null]}}';

    function jcSeed(): void
    {
        DB::table('jc_docs')->truncate();
        DB::table('jc_people')->truncate();
        DB::table('jc_unique_people')->truncate();

        DB::table('jc_docs')->insert([
            'id' => 1,
            'doc' => JC_DOC,
            'settings' => json_encode(['contact' => ['nric' => '880202026666', 'phone' => '0123456789', 'city' => 'Ipoh'], 'tier' => 'basic']),
        ]);

        $people = [
            ['id' => 1, 'nric' => '900101015555', 'profile' => '{"ids":{"nric":"880202026666","passport":"A1234567"},"theme":"dark"}'],
            ['id' => 2, 'nric' => '880202026666', 'profile' => '{"ids":{"nric":"900101015555"}}'],
            ['id' => 3, 'nric' => '770303037777', 'profile' => '{"ids":{"nric":"880202026666"}}'],
        ];

        DB::table('jc_people')->insert($people);
        DB::table('jc_unique_people')->insert($people);
    }

    /**
     * A live log of every select against one of the given tables recorded from now on.
     *
     * @return ArrayObject<int, string>
     */
    function jcDataSelects(string ...$tables): ArrayObject
    {
        $selects = new ArrayObject;

        DB::listen(function ($query) use ($selects, $tables): void {
            $sql = strtolower(ltrim($query->sql));

            if (! str_starts_with($sql, 'select')) {
                return;
            }

            foreach ($tables as $table) {
                if (str_contains($sql, 'from "'.$table.'"')) {
                    $selects->append($query->sql);
                }
            }
        });

        return $selects;
    }

    function jcConfigure(string $model, string $sanitizer): void
    {
        config()->set('pii.sanitizers', [$model => $sanitizer]);
        config()->set('pii.models', [$model]);
    }

    function jcRun(int $chunkSize = 10): RunReport
    {
        return app(SanitizationRunner::class)->run(new RunOptions(chunkSize: $chunkSize));
    }

    /** @return array<string, mixed> */
    function jcRunDoc(): array
    {
        jcConfigure(JcDoc::class, JcMatrixSanitizer::class);

        $report = jcRun();

        expect($report->failed())->toBeFalse();

        return json_decode((string) DB::table('jc_docs')->where('id', 1)->value('doc'), true);
    }

    /** @return array<string, array<int, object>> */
    function jcSnapshot(): array
    {
        return [
            'jc_docs' => DB::table('jc_docs')->orderBy('id')->get()->all(),
            'jc_people' => DB::table('jc_people')->orderBy('id')->get()->all(),
        ];
    }

    beforeEach(function () {
        JcLog::$calls = [];

        config()->set('pii.keyed.key', JC_KEY);

        Schema::create('jc_docs', function ($table) {
            $table->id();
            $table->text('doc')->nullable();
            $table->json('settings')->nullable();
        });

        Schema::create('jc_people', function ($table) {
            $table->id();
            $table->string('nric');
            $table->text('profile')->nullable();
        });

        Schema::create('jc_unique_people', function ($table) {
            $table->id();
            $table->string('nric')->unique();
            $table->text('profile')->nullable();
        });

        jcSeed();
    });

    // Group A: every value type applied to the path value (AC-5, R2.3, R3.4)

    it('applies a definition of each value type to its path value', function (string $path, Closure $check) {
        $doc = jcRunDoc();

        $check(data_get($doc, str_replace('->', '.', $path)), $doc);

        expect($doc['keep'])->toBe(['x' => 1, 'y' => [true, null]]);
    })->with([
        'static string' => ['static->string', fn ($v) => expect($v)->toBe('REDACTED-STATIC')],
        'static int' => ['static->int', fn ($v) => expect($v)->toBe(42)],
        'static array' => ['static->array', fn ($v) => expect($v)->toBe(['masked' => true])],
        'static null' => ['static->null', function ($v, $doc) {
            expect($v)->toBeNull();
            expect($doc['static'])->toHaveKey('null');
        }],
        'static enum' => ['static->enum', fn ($v) => expect($v)->toBe('gold')],
        'closure' => ['closure', function ($v) {
            expect($v)->toBe('C:9876543210');
            expect(JcLog::$calls)->toHaveCount(1);
            expect(JcLog::$calls[0][0])->toBe('0123456789');
            expect(JcLog::$calls[0][1])->toBeInstanceOf(Generator::class);
            expect(JcLog::$calls[0][2])->toBeInstanceOf(JcDoc::class);
            expect(JcLog::$calls[0][2]->getKey())->toBe(1);
        }],
        'invokable class-string' => ['invokable', fn ($v) => expect($v)->toBe('INV:inv-orig')],
        'Faker shorthand' => ['faker', function ($v) {
            expect(filter_var($v, FILTER_VALIDATE_EMAIL))->not->toBeFalse();
            expect($v)->not->toBe('f-orig');
        }],
        'ValueGenerator instance' => ['generator', fn ($v) => expect($v)->toBe('GEN:g-orig')],
        'Keyed pattern' => ['keyed', function ($v) {
            expect($v)->toBe(KeyedResolver::candidate(JC_KEY, 'jc-code', 'A-1234', 0, Keyed::pattern('jc-code', 'K-####')->shape(), 'A-1234'));
            expect($v)->toMatch('/^K-\d{4}$/');
        }],
        'Format bare' => ['format', function ($v) {
            expect($v)->toEndWith('6789');
            expect(strlen($v))->toBe(10);
            expect($v)->not->toBe('0123456789');
        }],
        'Malaysia bare' => ['malaysia', function ($v) {
            expect(MalaysiaFormats::nricIsValid($v))->toBeTrue();
            expect($v)->not->toBe('900101015555');
        }],
        'Malaysia shorthand' => ['malaysia_shorthand', fn ($v) => expect(MalaysiaFormats::nricIsValid($v))->toBeTrue()],
        'Keyed + Format' => ['keyed_format', function ($v) {
            expect($v)->toBe(KeyedResolver::candidate(JC_KEY, 'jc-phone', '0198765432', 0, Format::keepLast(4), '0198765432'));
            expect($v)->toEndWith('5432');
        }],
        'Keyed + Malaysia' => ['keyed_malaysia', function ($v) {
            expect($v)->toBe(KeyedResolver::candidate(JC_KEY, 'jc-nric', '880202026666', 0, Malaysia::nric(), '880202026666'));
            expect(MalaysiaFormats::nricIsValid($v))->toBeTrue();
        }],
    ]);

    it('composes on an array-cast carrier', function () {
        jcConfigure(JcCastDoc::class, JcCastSanitizer::class);

        expect(jcRun()->failed())->toBeFalse();

        $settings = JcCastDoc::find(1)->settings;

        expect($settings['contact']['city'])->toBe('Ipoh');
        expect($settings['tier'])->toBe('gold');
        expect($settings['contact']['phone'])->toEndWith('6789');
        expect(strlen($settings['contact']['phone']))->toBe(10);
        expect($settings['contact']['nric'])->toBe(KeyedResolver::candidate(JC_KEY, 'jc-nric', '880202026666', 0, Malaysia::nric(), '880202026666'));
    });

    it('reproduces bare Format, Malaysia and Faker path values under a seeded Faker', function () {
        jcConfigure(JcDoc::class, JcMatrixSanitizer::class);

        app(Generator::class)->seed(7);
        jcRun();
        $first = DB::table('jc_docs')->where('id', 1)->value('doc');

        jcSeed();
        app(Generator::class)->seed(7);
        jcRun();

        expect(DB::table('jc_docs')->where('id', 1)->value('doc'))->toBe($first);
    });

    // Group B: a Keyed path and a flat column share a namespace (AC-5 with AC-1)

    it('gives a Keyed path and a flat column the same replacement for the same input across rows and two runs', function (string $model, string $sanitizer, bool $flatUnique, string $table) {
        jcConfigure($model, $sanitizer);
        app(Generator::class)->seed(1);

        expect(jcRun(2)->failed())->toBeFalse();

        $rows = DB::table($table)->orderBy('id')->get();
        $profile = fn (int $i): array => json_decode($rows[$i]->profile, true);

        expect($profile(0)['ids']['nric'])->toBe($rows[1]->nric);
        expect($profile(1)['ids']['nric'])->toBe($rows[0]->nric);
        expect($profile(2)['ids']['nric'])->toBe($rows[1]->nric);

        $originals = ['900101015555', '880202026666', '770303037777'];

        foreach ($rows as $i => $row) {
            expect($row->nric)->not->toBe($originals[$i]);
        }

        expect($profile(0)['ids']['passport'])->toBe('A1234567');
        expect($profile(0)['theme'])->toBe('dark');

        expect(app(KeyedValueRegistry::class)->bindings('nric'))->toBe([
            ['connection' => null, 'table' => $table, 'column' => 'nric', 'path' => null, 'unique' => $flatUnique],
            ['connection' => null, 'table' => $table, 'column' => 'profile', 'path' => 'ids->nric', 'unique' => false],
        ]);
        expect(app(KeyedValueRegistry::class)->isUniqueBound('nric'))->toBe($flatUnique);

        $first = DB::table($table)->orderBy('id')->get(['id', 'nric', 'profile'])->all();

        jcSeed();
        app(Generator::class)->seed(999);
        jcRun(2);

        expect(DB::table($table)->orderBy('id')->get(['id', 'nric', 'profile'])->all())->toEqual($first);
    })->with([
        'pure namespace' => [JcPerson::class, JcPersonSanitizer::class, false, 'jc_people'],
        'unique-bound namespace' => [JcUniquePerson::class, JcUniquePersonSanitizer::class, true, 'jc_unique_people'],
    ]);

    it('names the column and the path when a Keyed path meets a float leaf', function () {
        DB::table('jc_docs')->insert(['id' => 2, 'doc' => '{"score":2.5}']);
        jcConfigure(JcDoc::class, JcFloatSanitizer::class);

        $report = jcRun();
        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(InvalidReplacementValueException::class);
        expect($chunk->failureMessage)->toContain('$doc: path score: The keyed value-definition cannot use an input of type float.');
    });

    // Group C: every path-definition boot rule fails before any row is read

    it('fails every path-definition boot rule before any row is read', function (string $model, string $sanitizer, ?Closure $setup, string $exception, string $fragment) {
        jcConfigure($model, $sanitizer);

        if ($setup !== null) {
            $setup();
        }

        $before = jcSnapshot();
        $selects = jcDataSelects('jc_docs', 'jc_people');

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10)))
            ->toThrow($exception, $fragment);

        expect($selects->getArrayCopy())->toBe([]);
        expect(jcSnapshot())->toEqual($before);
    })->with([
        'path-only Keyed without a key' => [JcDoc::class, JcKeyOnlyPathSanitizer::class, fn () => config()->set('pii.keyed.key', null), InvalidConfigurationException::class, 'PII_SANITIZER_KEY'],
        'flat and path shape mismatch' => [JcPerson::class, JcShapeMismatchSanitizer::class, null, InvalidConfigurationException::class, '"nric" is declared with different shapes'],
        'static object' => [JcDoc::class, JcStaticObjectSanitizer::class, null, InvalidConfigurationException::class, 'is a stdClass, which cannot be written into JSON'],
        'static non-finite float' => [JcDoc::class, JcStaticInfSanitizer::class, null, InvalidConfigurationException::class, 'is a float, which cannot be written into JSON'],
        'static array with a nested object' => [JcDoc::class, JcStaticNestedSanitizer::class, null, InvalidConfigurationException::class, 'is a ArrayObject, which cannot be written into JSON'],
        'categorical path map' => [JcDoc::class, JcCategoricalSanitizer::class, null, InvalidCategoricalColumnException::class, 'uses a Json::paths() definition'],
    ]);

    it('reports the static-value message byte-exactly', function () {
        jcConfigure(JcDoc::class, JcStaticObjectSanitizer::class);

        expect(fn () => jcRun())->toThrow(
            InvalidConfigurationException::class,
            '[laravel-pii-sanitizer] The static value for path "static->string" of JcDoc::$doc in JcStaticObjectSanitizer::fields() is a stdClass, which cannot be written into JSON. Use null, a scalar, an array or an enum.',
        );
    });

    it('records data selects during a successful matrix run (listener positive control)', function () {
        $selects = jcDataSelects('jc_docs');

        jcRunDoc();

        expect(count($selects))->toBeGreaterThan(0);
    });
}
