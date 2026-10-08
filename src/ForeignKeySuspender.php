<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;

/**
 * Run-scoped suspension of foreign-key enforcement for an opted-in mirror
 * group that has an FK edge (docs/design/referenced-identifier-structured-column-sanitization/spec,
 * "Enforced foreign keys"). Per-chunk transactions mean a parent and its
 * referencing tables always commit separately, so no write order and no
 * deferral satisfies an enforced FK; suspending enforcement for the run, on
 * the run's connection, is the only approach that works. Suspension also
 * stops ON UPDATE CASCADE, so every member is rewritten exactly once by its
 * own target.
 *
 * Every read goes through the write PDO (selectOne($sql, [], false)) so a
 * read/write-split connection reads the session it changes. Statements run
 * through statement(). Left non-final so a feature test can bind a subclass
 * double to exercise restore-failure handling.
 */
class ForeignKeySuspender
{
    private const SQLITE_IN_TRANSACTION = 'the SQLite connection is inside an open transaction, where PRAGMA foreign_keys cannot change';

    private const PGSQL_NOT_PERMITTED = 'the PostgreSQL role may not set session_replication_role (a superuser, or on PostgreSQL 15+ a GRANT SET ON PARAMETER session_replication_role, is required)';

    private const PGSQL_ROLES = ['origin', 'replica', 'local'];

    /**
     * Throws when suspension is not feasible on this connection. Runs at boot,
     * before any row is read, and on dry runs too.
     *
     * @throws UnsafeColumnException
     */
    public function assertSuspendable(Connection $connection): void
    {
        $driver = $connection->getDriverName();

        match ($driver) {
            'sqlite' => $this->assertSqliteSuspendable($connection),
            'mysql', 'mariadb' => null,
            'pgsql' => $this->assertPgsqlSuspendable($connection),
            default => throw $this->unsupported($driver),
        };
    }

    /**
     * @return ?\Closure(): void restores the setting read before suspending, or null when enforcement was already off and nothing was changed
     *
     * @throws UnsafeColumnException
     */
    public function suspend(Connection $connection): ?\Closure
    {
        $driver = $connection->getDriverName();

        return match ($driver) {
            'sqlite' => $this->suspendSqlite($connection),
            'mysql', 'mariadb' => $this->suspendMysql($connection),
            'pgsql' => $this->suspendPgsql($connection),
            default => throw $this->unsupported($driver),
        };
    }

    private function assertSqliteSuspendable(Connection $connection): void
    {
        if ($connection->transactionLevel() === 0) {
            return;
        }

        if ($this->readInt($connection, 'PRAGMA foreign_keys') !== 0) {
            throw UnsafeColumnException::foreignKeySuspensionUnavailable('sqlite', self::SQLITE_IN_TRANSACTION);
        }
    }

    private function suspendSqlite(Connection $connection): ?\Closure
    {
        if ($this->readInt($connection, 'PRAGMA foreign_keys') === 0) {
            return null;
        }

        // PRAGMA foreign_keys = OFF is a silent no-op inside an open
        // transaction, so refuse rather than report a suspension that did
        // not happen (assertSuspendable() normally catches this at boot).
        if ($connection->transactionLevel() > 0) {
            throw UnsafeColumnException::foreignKeySuspensionUnavailable('sqlite', self::SQLITE_IN_TRANSACTION);
        }

        $connection->statement('PRAGMA foreign_keys = OFF');

        return static function () use ($connection): void {
            $connection->statement('PRAGMA foreign_keys = ON');
        };
    }

    private function suspendMysql(Connection $connection): ?\Closure
    {
        if ($this->readInt($connection, 'SELECT @@SESSION.foreign_key_checks AS v') === 0) {
            return null;
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        return static function () use ($connection): void {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        };
    }

    private function assertPgsqlSuspendable(Connection $connection): void
    {
        $role = $this->readPgsqlRole($connection);

        if ($role === 'replica') {
            return;
        }

        try {
            $connection->statement("SET session_replication_role = 'replica'");
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '42501') {
                throw UnsafeColumnException::foreignKeySuspensionUnavailable('pgsql', self::PGSQL_NOT_PERMITTED);
            }

            throw $e;
        }

        $connection->statement($this->pgsqlRestoreSql($role));
    }

    private function suspendPgsql(Connection $connection): ?\Closure
    {
        $role = $this->readPgsqlRole($connection);

        if ($role === 'replica') {
            return null;
        }

        $connection->statement("SET session_replication_role = 'replica'");

        $restoreSql = $this->pgsqlRestoreSql($role);

        return static function () use ($connection, $restoreSql): void {
            $connection->statement($restoreSql);
        };
    }

    /**
     * @throws \UnexpectedValueException when the setting is outside origin, replica and local
     */
    private function readPgsqlRole(Connection $connection): string
    {
        $value = (string) $this->read($connection, "SELECT current_setting('session_replication_role') AS v");

        if (! in_array($value, self::PGSQL_ROLES, true)) {
            throw new \UnexpectedValueException('[laravel-pii-sanitizer] session_replication_role reported a value outside origin, replica and local, so it cannot be restored safely.');
        }

        return $value;
    }

    /** The restore SQL comes from a literal match on the closed set, never from the read value. */
    private function pgsqlRestoreSql(string $role): string
    {
        return match ($role) {
            'local' => "SET session_replication_role = 'local'",
            default => "SET session_replication_role = 'origin'",
        };
    }

    private function readInt(Connection $connection, string $sql): int
    {
        $value = $this->read($connection, $sql);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function read(Connection $connection, string $sql): mixed
    {
        $row = $connection->selectOne($sql, [], false);

        return array_values((array) $row)[0] ?? null;
    }

    private function unsupported(string $driver): UnsafeColumnException
    {
        return UnsafeColumnException::foreignKeySuspensionUnavailable(
            $driver,
            "the \"{$driver}\" driver is not supported for opted-in referenced columns"
        );
    }
}
