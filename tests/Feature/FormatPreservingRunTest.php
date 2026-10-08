<?php

namespace {
    use Faker\Generator;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Format;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class FpContact extends Model
    {
        protected $table = 'fp_contacts';

        protected $guarded = [];

        public $timestamps = false;
    }

    class FpIcGenerator implements ValueGenerator
    {
        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            return Format::keepLast(4)($value, $faker, $row);
        }
    }

    class FpContactSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => Format::keepLength(),
                'masked_name' => Format::keepLength('*'),
                'phone' => Format::keepLast(4),
                'account_no' => Format::keepPrefix(3),
                'personal_email' => Format::keepEmailDomain(),
                'email' => Keyed::using('email', Format::keepEmailDomain()),
                'alt_email' => Keyed::using('email', Format::keepEmailDomain()),
                'nickname' => fn ($value, $faker, $row) => Format::keepPrefix(1)($value, $faker, $row),
                'ic' => FpIcGenerator::class,
                'city' => 'city',
                'status' => 'redacted',
            ];
        }
    }
}

namespace {

    use Faker\Generator;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    function fpRows(): array
    {
        return [
            ['name' => 'Zoë Ñúñez', 'masked_name' => 'Ahmad 0123', 'phone' => '0123456789', 'account_no' => 'ACC-00012345', 'personal_email' => 'aminah.yusof@corp.example', 'email' => 'aminah.yusof@corp.example', 'alt_email' => null, 'nickname' => 'Minah', 'ic' => '900101015555', 'city' => 'x', 'status' => 'active'],
            ['name' => 'Lim Wei', 'masked_name' => 'Ahmad 0123', 'phone' => '0198765432', 'account_no' => 'ACC-00067890', 'personal_email' => 'lim.wei@corp.example', 'email' => 'lim.wei@corp.example', 'alt_email' => 'aminah.yusof@corp.example', 'nickname' => 'Wei', 'ic' => '880202026666', 'city' => 'y', 'status' => 'active'],
            ['name' => 'Raj Kumar', 'masked_name' => 'Ahmad 0123', 'phone' => '0171234567', 'account_no' => 'ACC-00099999', 'personal_email' => 'raj@mail.example', 'email' => 'raj@mail.example', 'alt_email' => null, 'nickname' => 'Raj', 'ic' => '770303037777', 'city' => 'z', 'status' => 'active'],
        ];
    }

    function fpSeed(): void
    {
        DB::table('fp_contacts')->truncate();
        DB::table('fp_contacts')->insert(fpRows());
    }

    beforeEach(function () {
        Schema::create('fp_contacts', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('masked_name');
            $table->string('phone');
            $table->string('account_no');
            $table->string('personal_email');
            $table->string('email')->unique();
            $table->string('alt_email')->nullable();
            $table->string('nickname');
            $table->string('ic');
            $table->string('city');
            $table->string('status');
        });

        config()->set('pii.keyed.key', str_repeat("\x01", 32));
        config()->set('pii.sanitizers', [FpContact::class => FpContactSanitizer::class]);
        config()->set('pii.models', [FpContact::class]);

        fpSeed();
    });

    it('preserves the intended portion of every column and replaces the rest', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeFalse();

        $originals = fpRows();
        $rows = DB::table('fp_contacts')->orderBy('id')->get();

        foreach ($rows as $i => $row) {
            $original = $originals[$i];
            $domain = substr($original['email'], strrpos($original['email'], '@'));

            expect($row->phone)->toEndWith(substr($original['phone'], -4))->not->toBe($original['phone']);
            expect(strlen($row->phone))->toBe(strlen($original['phone']));
            expect($row->account_no)->toStartWith('ACC')->not->toBe($original['account_no']);
            expect(strlen($row->account_no))->toBe(strlen($original['account_no']));

            foreach (['personal_email', 'email'] as $column) {
                expect($row->{$column})->toEndWith($domain);
                expect(strstr($row->{$column}, '@', true))->not->toBe(strstr($original[$column], '@', true));
                expect(strlen($row->{$column}))->toBe(strlen($original[$column]));
            }

            expect(mb_strlen($row->name))->toBe(mb_strlen($original['name']));
            expect($row->name)->not->toBe($original['name']);
            expect(mb_strpos($row->name, ' '))->toBe(mb_strpos($original['name'], ' '));

            expect($row->masked_name)->toBe('***** ****');
            expect(mb_substr($row->nickname, 0, 1))->toBe(mb_substr($original['nickname'], 0, 1));
            expect(mb_strlen($row->nickname))->toBe(mb_strlen($original['nickname']));
            expect($row->ic)->toEndWith(substr($original['ic'], -4))->not->toBe($original['ic']);
            expect($row->status)->toBe('redacted');
            expect($row->city)->toBeString()->not->toBe('');
        }
    });

    it('keeps keyed output distinct on a unique column and mirrors it across columns', function () {
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        $rows = DB::table('fp_contacts')->orderBy('id')->get();

        expect($rows->pluck('email')->unique())->toHaveCount(3);
        expect($rows[1]->alt_email)->toBe($rows[0]->email);
        expect($rows[2]->alt_email)->toBeNull();
    });

    it('is deterministic for keyed columns across runs on reseeded data', function () {
        $snapshot = fn () => DB::table('fp_contacts')->orderBy('id')->get(['id', 'email', 'alt_email'])->map(fn ($r) => (array) $r)->all();

        app(Generator::class)->seed(1);
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));
        $first = $snapshot();

        fpSeed();

        app(Generator::class)->seed(999);
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($snapshot())->toBe($first);
    });
}
