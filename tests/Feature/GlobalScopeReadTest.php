<?php

namespace {
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\SoftDeletes;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class GsSoftUser extends Model
    {
        use SoftDeletes;

        protected $table = 'gs_soft_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class GsSoftUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'REDACTED'];
        }
    }

    class GsScopedUser extends Model
    {
        protected $table = 'gs_scoped_users';

        protected $guarded = [];

        public $timestamps = false;

        protected static function booted(): void
        {
            static::addGlobalScope('active', fn (Builder $query) => $query->where('active', true));
        }
    }

    class GsScopedUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'REDACTED'];
        }
    }

    class GsSoftMember extends Model
    {
        use SoftDeletes;

        protected $table = 'gs_soft_members';

        protected $guarded = [];

        public $timestamps = false;
    }

    class GsSoftMemberSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'REDACTED'];
        }
    }
}

namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkSizer;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    it('sanitizes soft-deleted rows, which the SoftDeletes global scope would otherwise hide (BUG-1)', function () {
        Schema::create('gs_soft_users', function ($table) {
            $table->id();
            $table->string('email');
            $table->softDeletes();
        });

        DB::table('gs_soft_users')->insert(['email' => 'alive@real.com', 'deleted_at' => null]);
        DB::table('gs_soft_users')->insert(['email' => 'gone@real.com', 'deleted_at' => '2024-01-01 00:00:00']);
        DB::table('gs_soft_users')->insert(['email' => 'gone2@real.com', 'deleted_at' => '2024-01-02 00:00:00']);

        config()->set('pii.sanitizers', [GsSoftUser::class => GsSoftUserSanitizer::class]);
        config()->set('pii.models', [GsSoftUser::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsScanned)->toBe(3);
        expect($report->models[0]->rowsSanitized())->toBe(3);
        expect($report->models[0]->expectedChunks)->toBe(2);

        expect(DB::table('gs_soft_users')->pluck('email')->all())->toBe(['REDACTED', 'REDACTED', 'REDACTED']);

        // The soft-delete marker itself is untouched.
        expect(DB::table('gs_soft_users')->whereNotNull('deleted_at')->count())->toBe(2);
    });

    it('sanitizes rows hidden by any other global scope (BUG-1)', function () {
        Schema::create('gs_scoped_users', function ($table) {
            $table->id();
            $table->string('email');
            $table->boolean('active');
        });

        DB::table('gs_scoped_users')->insert(['email' => 'on@real.com', 'active' => true]);
        DB::table('gs_scoped_users')->insert(['email' => 'off@real.com', 'active' => false]);

        config()->set('pii.sanitizers', [GsScopedUser::class => GsScopedUserSanitizer::class]);
        config()->set('pii.models', [GsScopedUser::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(2);
        expect(DB::table('gs_scoped_users')->pluck('email')->all())->toBe(['REDACTED', 'REDACTED']);
    });

    it('sanitizes soft-deleted rows on the key-set read path too (BUG-1)', function () {
        Schema::create('gs_soft_members', function ($table) {
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('member_id');
            $table->string('email');
            $table->softDeletes();
            $table->primary(['org_id', 'member_id']);
        });

        foreach (range(1, 5) as $member) {
            DB::table('gs_soft_members')->insert([
                'org_id' => 1,
                'member_id' => $member,
                'email' => "m{$member}@real.com",
                'deleted_at' => $member % 2 === 0 ? '2024-01-01 00:00:00' : null,
            ]);
        }

        config()->set('pii.sanitizers', [GsSoftMember::class => GsSoftMemberSanitizer::class]);
        config()->set('pii.models', [GsSoftMember::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(5);
        expect($report->models[0]->expectedChunks)->toBe(3);
        expect(DB::table('gs_soft_members')->where('email', '!=', 'REDACTED')->count())->toBe(0);
    });

    it('counts soft-deleted rows in ChunkSizer::countFor() (BUG-1)', function () {
        Schema::create('gs_soft_users', function ($table) {
            $table->id();
            $table->string('email');
            $table->softDeletes();
        });

        DB::table('gs_soft_users')->insert(['email' => 'alive@real.com', 'deleted_at' => null]);
        DB::table('gs_soft_users')->insert(['email' => 'gone@real.com', 'deleted_at' => '2024-01-01 00:00:00']);

        expect((new ChunkSizer)->countFor(new GsSoftUser))->toBe(2);
    });
}
