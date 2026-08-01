<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class ConfigUser extends Model
    {
        protected $table = 'config_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class ConfigUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => 'name',
            ];
        }
    }

    class ConfigPost extends Model
    {
        protected $table = 'config_posts';

        protected $guarded = [];

        public $timestamps = false;
    }

    class ConfigPostSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'title' => 'sentence',
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
        Schema::create('config_users', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('config_posts', function ($table) {
            $table->id();
            $table->string('title');
        });

        DB::table('config_users')->insert(['name' => 'seed user']);
        DB::table('config_posts')->insert(['title' => 'seed post']);

        config()->set('pii.sanitizers', [
            ConfigUser::class => ConfigUserSanitizer::class,
            ConfigPost::class => ConfigPostSanitizer::class,
        ]);
    });

    afterEach(function () {
        app()->detectEnvironment(fn () => 'testing');

        if (getenv('PII_CHUNK_SIZE') !== false) {
            putenv('PII_CHUNK_SIZE');
            unset($_ENV['PII_CHUNK_SIZE']);
        }
    });

    it('honors the active environment config for the model list', function () {
        // Configuration A.
        config()->set('pii.models', [ConfigUser::class]);
        $report = app(SanitizationRunner::class)->run(new RunOptions);
        expect($report->models)->toHaveCount(1);
        expect($report->models[0]->modelClass)->toBe(ConfigUser::class);

        // Configuration B.
        config()->set('pii.models', [ConfigPost::class]);
        $report = app(SanitizationRunner::class)->run(new RunOptions);
        expect($report->models)->toHaveCount(1);
        expect($report->models[0]->modelClass)->toBe(ConfigPost::class);
    });

    it('honors the active environment config for the chunk size', function () {
        config()->set('pii.models', [ConfigUser::class]);

        // Configuration A.
        config()->set('pii.chunk.size', 5);
        $report = app(SanitizationRunner::class)->run(new RunOptions);
        expect($report->models[0]->chunkSize)->toBe(5);
        expect($report->models[0]->automaticChunkSize)->toBeFalse();

        // Configuration B.
        config()->set('pii.chunk.size', 20);
        $report = app(SanitizationRunner::class)->run(new RunOptions);
        expect($report->models[0]->chunkSize)->toBe(20);

        // Configuration C.
        config()->set('pii.chunk.size', null);
        $report = app(SanitizationRunner::class)->run(new RunOptions);
        expect($report->models[0]->automaticChunkSize)->toBeTrue();
    });

    it('honors the active environment allow-list', function () {
        config()->set('pii.models', [ConfigUser::class]);
        app()->detectEnvironment(fn () => 'dev');

        // Configuration A.
        config()->set('pii.environments', ['local', 'testing']);
        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions))
            ->toThrow(UnsafeEnvironmentException::class);

        // Configuration B.
        config()->set('pii.environments', ['local', 'testing', 'dev']);
        $report = app(SanitizationRunner::class)->run(new RunOptions);
        expect($report->failed())->toBeFalse();
    });

    it('resolves chunk.size from the PII_CHUNK_SIZE environment variable', function () {
        putenv('PII_CHUNK_SIZE=250');
        $_ENV['PII_CHUNK_SIZE'] = '250';

        $config = require __DIR__.'/../../config/pii.php';
        expect((int) $config['chunk']['size'])->toBe(250);

        putenv('PII_CHUNK_SIZE');
        unset($_ENV['PII_CHUNK_SIZE']);

        $config = require __DIR__.'/../../config/pii.php';
        expect($config['chunk']['size'])->toBeNull();
    });

    it('reads no environment variable outside the config file', function () {
        $srcDir = __DIR__.'/../../src';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (preg_match('/\benv\s*\(/', $contents)) {
                $offenders[] = $file->getPathname();
            }
        }

        expect($offenders)->toBe([]);
    });

    it('resolves chunk sub-key defaults when the chunk array is only partially configured', function () {
        config()->set('pii.models', [ConfigUser::class]);
        config()->set('pii.chunk', ['size' => null]);

        $report = app(SanitizationRunner::class)->run(new RunOptions);

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->automaticChunkSize)->toBeTrue();
        expect($report->models[0]->chunkSize)->toBeGreaterThan(0);
    });
}
