<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Format;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

    class NutPhone extends Model
    {
        protected $table = 'nut_phones';

        protected $guarded = [];

        public $timestamps = false;
    }

    class NutPhoneFormatSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['phone' => Format::keepLast(2)];
        }
    }

    class NutPhoneMalaysiaSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['phone' => Malaysia::nric()];
        }
    }

    class NutAccount extends Model
    {
        protected $table = 'nut_accounts';

        protected $guarded = [];

        public $timestamps = false;
    }

    class NutAccountSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => Keyed::using('nut-email', Format::keepEmailDomain())];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    // BUG-14: a unique index never treats NULLs as equal (MySQL, PostgreSQL
    // and SQLite alike), so a unique tuple holding a NULL in any member, from
    // any definition type, must not be counted as a collision.

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x03", 32));

        Schema::create('nut_phones', function ($table) {
            $table->id();
            $table->string('phone')->nullable()->unique();
        });

        DB::table('nut_phones')->insert([['phone' => null], ['phone' => '850101-14-5678'], ['phone' => null]]);
    });

    it('keeps NULL on a nullable unique column with a bare format or Malaysia helper', function (string $sanitizer) {
        config()->set('pii.sanitizers', [NutPhone::class => $sanitizer]);
        config()->set('pii.models', [NutPhone::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();

        $after = DB::table('nut_phones')->orderBy('id')->pluck('phone')->all();

        expect($after[0])->toBeNull();
        expect($after[2])->toBeNull();
        expect($after[1])->not->toBe('850101-14-5678');
        expect($after[1])->not->toBeNull();
    })->with([
        'Format::keepLast(2)' => [NutPhoneFormatSanitizer::class],
        'Malaysia::nric()' => [NutPhoneMalaysiaSanitizer::class],
    ]);

    it('allows a repeated Keyed value next to a NULL member of a composite unique index', function () {
        Schema::create('nut_accounts', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('deleted_at')->nullable();
            $table->unique(['email', 'deleted_at']);
        });

        DB::table('nut_accounts')->insert([
            ['email' => 'same@example.com', 'deleted_at' => null],
            ['email' => 'same@example.com', 'deleted_at' => null],
        ]);

        config()->set('pii.sanitizers', [NutAccount::class => NutAccountSanitizer::class]);
        config()->set('pii.models', [NutAccount::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();

        $after = DB::table('nut_accounts')->orderBy('id')->pluck('email')->all();

        expect($after[0])->not->toBe('same@example.com');
        expect($after[0])->toEndWith('@example.com');
        expect($after[1])->toBe($after[0]);
    });
}
