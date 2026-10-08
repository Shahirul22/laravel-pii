<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class KbUser extends Model
    {
        protected $table = 'kb_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class KbOther extends Model
    {
        protected $table = 'kb_others';

        protected $guarded = [];

        public $timestamps = false;
    }

    class KbUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => Keyed::pattern('kb', '###')];
        }
    }

    class KbOtherSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => Keyed::pattern('kb', '####')];
        }
    }

    class KbPlainSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => fn ($value) => 'plain-'.$value];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    /**
     * @param  array<class-string, class-string>  $map
     */
    function kbConfigure(array $map): void
    {
        config()->set('pii.sanitizers', $map);
        config()->set('pii.models', array_keys($map));
    }

    /**
     * @return array<string, list<string>>
     */
    function kbSnapshot(): array
    {
        return [
            'kb_users' => DB::table('kb_users')->orderBy('id')->pluck('code')->all(),
            'kb_others' => DB::table('kb_others')->orderBy('id')->pluck('code')->all(),
        ];
    }

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x01", 32));

        Schema::create('kb_users', function ($table) {
            $table->id();
            $table->string('code')->unique();
        });
        Schema::create('kb_others', function ($table) {
            $table->id();
            $table->string('code');
        });

        for ($i = 0; $i < 5; $i++) {
            DB::table('kb_users')->insert(['code' => "u{$i}"]);
            DB::table('kb_others')->insert(['code' => "o{$i}"]);
        }
    });

    it('fails fast on a missing key, before any row is written', function () {
        config()->set('pii.keyed.key', null);
        kbConfigure([KbUser::class => KbUserSanitizer::class]);
        $before = kbSnapshot();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions))
            ->toThrow(InvalidConfigurationException::class, 'PII_SANITIZER_KEY');

        expect(kbSnapshot())->toBe($before);
    });

    it('fails fast on a key shorter than 32 bytes', function () {
        config()->set('pii.keyed.key', str_repeat('a', 31));
        kbConfigure([KbUser::class => KbUserSanitizer::class]);
        $before = kbSnapshot();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions))
            ->toThrow(InvalidConfigurationException::class);

        expect(kbSnapshot())->toBe($before);
    });

    it('fails fast on a shape mismatch across two targets, before any row is written', function () {
        kbConfigure([KbUser::class => KbUserSanitizer::class, KbOther::class => KbOtherSanitizer::class]);
        $before = kbSnapshot();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions))
            ->toThrow(InvalidConfigurationException::class, '"kb"');

        expect(kbSnapshot())->toBe($before);
    });

    it('does not require a key when no Keyed field is declared', function () {
        config()->set('pii.keyed.key', null);
        kbConfigure([KbUser::class => KbPlainSanitizer::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions);

        expect($report->failed())->toBeFalse();
    });

    it('still requires the key on a dry run', function () {
        config()->set('pii.keyed.key', null);
        kbConfigure([KbUser::class => KbUserSanitizer::class]);

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(dryRun: true)))
            ->toThrow(InvalidConfigurationException::class);
    });
}
