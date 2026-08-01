<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\SoftDeletes;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class TimestampUser extends Model
    {
        protected $table = 'timestamp_users';

        protected $guarded = [];
    }

    class TimestampSoftDeleteUser extends Model
    {
        use SoftDeletes;

        protected $table = 'timestamp_soft_users';

        protected $guarded = [];
    }

    class TimestamplessUser extends Model
    {
        protected $table = 'timestampless_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class TimestampCustomColumnUser extends Model
    {
        protected $table = 'timestamp_custom_users';

        protected $guarded = [];

        const CREATED_AT = 'made_at';

        const UPDATED_AT = 'touched_at';
    }

    class TimestampDeclaringSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['updated_at' => fn () => '2020-01-01 00:00:00'];
        }
    }

    class TimestampPlainSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }
}

namespace {

    use Shahirul22\LaravelPiiSanitizer\TimestampPolicy;

    it('derives the default protected set from the model', function () {
        $policy = new TimestampPolicy;

        $columns = $policy->protectedColumns(new TimestampUser);

        expect($columns)->toContain('created_at');
        expect($columns)->toContain('updated_at');
    });

    it('honours a model\'s custom timestamp column names', function () {
        $policy = new TimestampPolicy;

        $columns = $policy->protectedColumns(new TimestampCustomColumnUser);

        expect($columns)->toContain('made_at');
        expect($columns)->toContain('touched_at');
    });

    it('includes the soft-delete column when the model uses SoftDeletes', function () {
        $policy = new TimestampPolicy;

        $columns = $policy->protectedColumns(new TimestampSoftDeleteUser);

        expect($columns)->toContain('deleted_at');
    });

    it('merges additional protected columns from config', function () {
        config()->set('pii.protected_columns', ['created_at', 'updated_at', 'deleted_at', 'audited_at']);

        $policy = new TimestampPolicy;

        $columns = $policy->protectedColumns(new TimestampUser);

        expect($columns)->toContain('audited_at');
        expect($columns)->toBe(array_values(array_unique($columns)));
    });

    it('still returns the config columns for a model with timestamps disabled', function () {
        $policy = new TimestampPolicy;

        $columns = $policy->protectedColumns(new TimestamplessUser);

        expect($columns)->not->toContain(null);

        foreach ($columns as $column) {
            expect($column)->toBeString();
        }
    });

    it('reports that automatic timestamp maintenance must be suppressed for a timestamped model', function () {
        $policy = new TimestampPolicy;

        expect($policy->mustSuppressAutomaticTimestamps(new TimestampPlainSanitizer, new TimestampUser))->toBeTrue();
    });

    it('reports suppression for a timestamped model even when the sanitizer explicitly declares a timestamp column', function () {
        $policy = new TimestampPolicy;

        expect($policy->mustSuppressAutomaticTimestamps(new TimestampDeclaringSanitizer, new TimestampUser))->toBeTrue();
    });

    it('reports no suppression needed when the model does not maintain timestamps', function () {
        $policy = new TimestampPolicy;

        expect($policy->mustSuppressAutomaticTimestamps(new TimestampPlainSanitizer, new TimestamplessUser))->toBeFalse();
    });
}
