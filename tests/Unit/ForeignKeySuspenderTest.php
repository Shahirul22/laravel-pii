<?php

namespace {
    use Illuminate\Database\Connection;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Facades\DB;
    use Mockery\MockInterface;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\ForeignKeySuspender;

    const FKS_PREFIX = '[laravel-pii-sanitizer] Opted-in referenced columns need foreign-key enforcement suspended for this run, which is not possible here: ';

    function fksPragma(): int
    {
        return (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
    }

    function fksStub(string $driver, string $sql, mixed $value, int $transactionLevel = 0): MockInterface
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn($driver);
        $connection->shouldReceive('transactionLevel')->andReturn($transactionLevel);
        $connection->shouldReceive('selectOne')->with($sql, [], false)->andReturn((object) ['v' => $value]);

        return $connection;
    }

    function fksPdoException(string $code): PDOException
    {
        return new class($code) extends PDOException
        {
            public function __construct(string $code)
            {
                parent::__construct('permission denied');
                $this->code = $code;
            }
        };
    }

    it('does not touch a SQLite connection whose enforcement is already off', function () {
        DB::flushQueryLog();
        DB::enableQueryLog();

        expect(fksPragma())->toBe(0);
        expect((new ForeignKeySuspender)->suspend(DB::connection()))->toBeNull();

        $writes = array_filter(DB::getQueryLog(), fn ($e) => stripos($e['query'], 'foreign_keys = off') !== false);

        expect($writes)->toBe([]);
    });

    it('suspends and restores SQLite foreign-key enforcement', function () {
        DB::statement('PRAGMA foreign_keys = ON');
        expect(fksPragma())->toBe(1);

        $suspender = new ForeignKeySuspender;
        $suspender->assertSuspendable(DB::connection());

        $restore = $suspender->suspend(DB::connection());

        expect($restore)->toBeInstanceOf(Closure::class);
        expect(fksPragma())->toBe(0);

        $restore();

        expect(fksPragma())->toBe(1);
    });

    it('refuses to suspend SQLite inside an open transaction when enforcement is on', function () {
        DB::statement('PRAGMA foreign_keys = ON');
        expect(fksPragma())->toBe(1);

        DB::beginTransaction();

        try {
            expect(fn () => (new ForeignKeySuspender)->assertSuspendable(DB::connection()))
                ->toThrow(UnsafeColumnException::class, FKS_PREFIX.'the SQLite connection is inside an open transaction, where PRAGMA foreign_keys cannot change.');
        } finally {
            DB::rollBack();
            DB::statement('PRAGMA foreign_keys = OFF');
        }
    });

    it('allows SQLite inside an open transaction when enforcement is already off', function () {
        DB::beginTransaction();

        try {
            (new ForeignKeySuspender)->assertSuspendable(DB::connection());

            expect(true)->toBeTrue();
        } finally {
            DB::rollBack();
        }
    });

    it('throws from suspend() on SQLite when the read value is 1 inside a transaction', function () {
        $connection = fksStub('sqlite', 'PRAGMA foreign_keys', 1, 1);
        $connection->shouldNotReceive('statement');

        expect(fn () => (new ForeignKeySuspender)->suspend($connection))
            ->toThrow(UnsafeColumnException::class, 'the SQLite connection is inside an open transaction');
    });

    it('suspends and restores MySQL-family foreign_key_checks', function (string $driver) {
        $connection = fksStub($driver, 'SELECT @@SESSION.foreign_key_checks AS v', 1);
        $connection->shouldReceive('statement')->once()->with('SET FOREIGN_KEY_CHECKS=0')->ordered();
        $connection->shouldReceive('statement')->once()->with('SET FOREIGN_KEY_CHECKS=1')->ordered();

        $restore = (new ForeignKeySuspender)->suspend($connection);

        expect($restore)->toBeInstanceOf(Closure::class);

        $restore();
    })->with(['mysql', 'mariadb']);

    it('returns null for MySQL-family when foreign_key_checks is already 0', function (string $driver) {
        $connection = fksStub($driver, 'SELECT @@SESSION.foreign_key_checks AS v', 0);
        $connection->shouldNotReceive('statement');

        expect((new ForeignKeySuspender)->suspend($connection))->toBeNull();
    })->with(['mysql', 'mariadb']);

    it('makes no statement call when asserting MySQL-family suspendability', function (string $driver) {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn($driver);
        $connection->shouldNotReceive('statement');
        $connection->shouldNotReceive('selectOne');

        (new ForeignKeySuspender)->assertSuspendable($connection);

        expect(true)->toBeTrue();
    })->with(['mysql', 'mariadb']);

    const FKS_PG_READ = "SELECT current_setting('session_replication_role') AS v";

    it('suspends PostgreSQL by setting replica and restores the read role', function (string $role) {
        $connection = fksStub('pgsql', FKS_PG_READ, $role);
        $connection->shouldReceive('statement')->once()->with("SET session_replication_role = 'replica'")->ordered();
        $connection->shouldReceive('statement')->once()->with("SET session_replication_role = '{$role}'")->ordered();

        $restore = (new ForeignKeySuspender)->suspend($connection);

        expect($restore)->toBeInstanceOf(Closure::class);

        $restore();
    })->with(['origin', 'local']);

    it('treats PostgreSQL replica as already off', function () {
        $connection = fksStub('pgsql', FKS_PG_READ, 'replica');
        $connection->shouldNotReceive('statement');

        $suspender = new ForeignKeySuspender;

        expect($suspender->suspend($connection))->toBeNull();

        $suspender->assertSuspendable($connection);
    });

    it('probes PostgreSQL feasibility by setting replica then restoring origin', function () {
        $connection = fksStub('pgsql', FKS_PG_READ, 'origin');
        $connection->shouldReceive('statement')->once()->with("SET session_replication_role = 'replica'")->ordered();
        $connection->shouldReceive('statement')->once()->with("SET session_replication_role = 'origin'")->ordered();

        (new ForeignKeySuspender)->assertSuspendable($connection);

        expect(true)->toBeTrue();
    });

    it('reports PostgreSQL SQLSTATE 42501 as not suspendable', function () {
        $connection = fksStub('pgsql', FKS_PG_READ, 'origin');
        $connection->shouldReceive('statement')->once()->with("SET session_replication_role = 'replica'")
            ->andThrow(new QueryException('pgsql', "SET session_replication_role = 'replica'", [], fksPdoException('42501')));

        expect(fn () => (new ForeignKeySuspender)->assertSuspendable($connection))
            ->toThrow(UnsafeColumnException::class, FKS_PREFIX.'the PostgreSQL role may not set session_replication_role (a superuser, or on PostgreSQL 15+ a GRANT SET ON PARAMETER session_replication_role, is required).');
    });

    it('re-throws a PostgreSQL QueryException with any other code unchanged', function () {
        $exception = new QueryException('pgsql', "SET session_replication_role = 'replica'", [], fksPdoException('08006'));

        $connection = fksStub('pgsql', FKS_PG_READ, 'origin');
        $connection->shouldReceive('statement')->once()->andThrow($exception);

        try {
            (new ForeignKeySuspender)->assertSuspendable($connection);
            $this->fail('Expected the QueryException.');
        } catch (QueryException $caught) {
            expect($caught)->toBe($exception);
        }
    });

    it('rejects a PostgreSQL role value outside the closed set', function () {
        $connection = fksStub('pgsql', FKS_PG_READ, 'weird');
        $connection->shouldNotReceive('statement');

        $suspender = new ForeignKeySuspender;

        expect(fn () => $suspender->suspend($connection))->toThrow(UnexpectedValueException::class);
        expect(fn () => $suspender->assertSuspendable($connection))->toThrow(UnexpectedValueException::class);
    });

    it('refuses an unsupported driver from both methods', function () {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlsrv');
        $connection->shouldNotReceive('statement');

        $suspender = new ForeignKeySuspender;

        expect(fn () => $suspender->assertSuspendable($connection))
            ->toThrow(UnsafeColumnException::class, 'which is not possible here: the "sqlsrv" driver is not supported for opted-in referenced columns.');
        expect(fn () => $suspender->suspend($connection))
            ->toThrow(UnsafeColumnException::class, 'which is not possible here: the "sqlsrv" driver is not supported for opted-in referenced columns.');
    });

    it('reports that only PostgreSQL suspension also stops ordinary triggers', function (string $driver, bool $expected) {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn($driver);

        expect((new ForeignKeySuspender)->suspendsTriggers($connection))->toBe($expected);
    })->with([
        'pgsql' => ['pgsql', true],
        'mysql' => ['mysql', false],
        'mariadb' => ['mariadb', false],
        'sqlite' => ['sqlite', false],
    ]);
}
