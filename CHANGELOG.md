# Changelog

All notable changes to `shahirul22/laravel-pii-sanitizer` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-10-08

Version 2 of the sanitizer. It adds opt-in value generators, JSON paths, mirrored foreign-key columns and model-less table support. It also changes several behaviours on upgrade, listed first. Read [Upgrading from 0.1.0](#upgrading-from-010) before you update.

### Upgrading from 0.1.0

These behaviours changed. A sanitizer or a run that worked on 0.1.0 may now be refused at boot, fail a chunk, or write a different value.

Breaking behaviours:

- **Composite-primary-key protection.** Every column of a composite primary key is now rejected as a primary-key column, like a single-column key, unless you opt it in. A table is paged by its real identity (composite, UUID, keyless).
- **Boot-time static-value constraint checks.** A static value that cannot fit its column (length, `NOT NULL`, enum or set membership) is rejected at boot, before any row is read.
- **Cast-aware writes.** A value is written through the model's cast. A value that is already hashed or encrypted, written to a column with a hashing or encrypting mutator, is now encoded a second time.
- **Multi-attribute-cast and encode-failure refusals.** A cast or mutator that also writes to other, undeclared attributes is refused at boot, and a replacement the cast cannot encode is refused with a named `UnsupportedCastException`.
- **Per-row constraint validation.** A value from a closure, a Faker or an invokable definition, or a value on a cast column, is checked against the column's type, length, allowed values and `NULL` rule for every row. A violation stops the run with a `ConstraintViolationException` that names the column, and the value is never cut or changed to fit.

Other behaviour changes:

- Soft-deleted rows and other rows hidden by a global scope are now sanitized. Before, they kept their real data.
- A unique index that is partial, or that holds an expression, is no longer trusted as a paging identity. A primary key with a cast or accessor is paged by its raw stored value.
- An empty `fields()` is rejected at boot. A `categorical()` column whose cast or set mutator is not idempotent (`array`, `json`, `object`, `collection`, `encrypted`, `hashed`, custom cast classes, set mutators) is rejected at boot. Scalar, date and enum casts are accepted.
- Unique-value tracking is type-aware. Strings are compared without regard to case, and on integer, decimal and boolean columns numbers are compared by value, so a replacement `'1'` collides with a stored `1`. A tuple that holds `NULL` never collides. Runs that failed with a database duplicate error now retry.
- An empty or malformed JSON text under an `array` cast now fails with a named exception. It no longer silently writes `NULL`. An undecodable cast value no longer fails a static or Faker-name definition. A bare-scalar JSON document under a cast column skips every path.
- The foreign-key guards now see tables in non-default schemas, tables without the connection prefix, and SQLite implicit foreign keys. Names are compared the way the database compares them (case-insensitively on SQLite, and for MySQL and MariaDB database and column names). A configuration that booted before can now be rejected, correctly.
- A column that references its own table (for example `employees.manager_staff_no`) is rejected like any other referenced column.
- A paging identity that is an `enum` (MySQL, MariaDB), a binary column (PostgreSQL, SQLite) or a single-precision `FLOAT` (MySQL, MariaDB) is refused instead of silently skipping rows.
- MySQL and MariaDB `ON UPDATE CURRENT_TIMESTAMP` columns are no longer moved by a run. `enum` and `set` members keep their case in the allowed-value check, and `tinyint(1)` no longer limits a column to booleans.
- On PostgreSQL the batched `UPDATE` casts bound values to the column type, so integer, date, float, boolean, `character(n)` and `bit(n)` columns work without truncation.
- A mistyped `--model` class, or a mistyped class in `pii.models` or `pii.sanitizers`, now gives the package's own error, including an existing class that is not a model or a sanitizer. The chunk-failure messages changed and no longer mention logs, and the failure line withholds identity values that are not integers, UUIDs or ULIDs.
- Schema caches and run state (the keyed registry and its key, the uniqueness tracker, the sampler) are cleared at the start and at the end of every run.

API changes for code that extends the package:

- `SanitizerResolverContract` has a new abstract method, `resolveTable(string $table): ?Sanitizer`. A third-party implementer must add it.
- `SchemaGuard` has a new constructor with more arguments. Resolve it from the container.
- `InvalidConfigurationException::invalidSanitizerClass()` has a new required second argument, `?string $configKey`.
- New exception factories: `UnsafeColumnException::unresolvedForeignKey()`, `UnsafeColumnException::malformedForeignKey()`, `InvalidConfigurationException::mirrorEntryWithoutColumn()`, `InvalidConfigurationException::emptyFields()`, `UnsupportedCastException::undecodableValue()`.
- `RunReport::toArray()` has a new `triggersSuspended` key. `RunReport`, `ColumnConstraints` and `UniqueValueTracker` gained optional constructor parameters, and `ForeignKeyInspector` gained `columnKey()`.
- The `config/pii.php` file has two new keys, `tables` and `keyed`.
- `composer.json` now requires `ext-mbstring`, `ext-hash` and `ext-random`, and `composer test` runs larastan, pint and Pest.

### Added

- The `Keyed` generator: deterministic, HMAC-SHA256 based values that map the same input to the same output, with the secret in `PII_SANITIZER_KEY` and a namespace per mapping.
- The `Format` helpers for format-preserving replacements, and the `Malaysia` generators.
- `Sanitizer::mirrors()` to opt a referenced column in together with every column that holds the same value, with foreign-key enforcement suspended for the run on SQLite, MySQL, MariaDB and PostgreSQL.
- `Json::paths` to sanitize paths inside JSON columns, with or without an `array` cast.
- Composite, UUID and keyless primary-key support, an optional `pagingKey()`, and model-less table support through `pii.tables`.
- Cast-aware writes and column-constraint validation, with checks per database driver.

### Known limits

- **PostgreSQL role privilege.** Opted-in referenced columns need `session_replication_role` to be settable. The role must be a superuser or, on PostgreSQL 15 and later, hold `GRANT SET ON PARAMETER session_replication_role`. A role without it is refused at boot with a clear message and nothing is written, but `pii:sanitize` shows that message inside a Laravel exception stack trace instead of a clean console error.
- PostgreSQL foreign-key suspension also stops ordinary triggers for the run. Triggers that are `ENABLE ALWAYS` or `ENABLE REPLICA` still fire. The notice names triggers.
- `php artisan pii:sanitize --env=local` makes the environment guard pass on any machine. It is an override, by design.
- A column that a database trigger rewrites on update must not be part of a paging identity. The package does not detect it.
- The Malaysian NRIC place-of-birth codes and birthdate range, the SST format and the bank account lengths are assumptions that were not checked against the official sources. See the README.
- Driver coverage: SQLite, MySQL 8.4 and PostgreSQL 17 and 18 were run against real servers. MariaDB was checked only against stubs and SQLite, and PostgreSQL triggers and large tables were not run. Laravel 11 is not tested.
- The integration review of this release ended its last round `blocked`, at the review round limit. Every confirmed finding was fixed and tested, but a further review did not confirm it.
- Not shipped: sanitizing a true foreign-key column, reference-graph auto-discovery, multi-connection support, rule discovery and a coverage warning.

## [0.1.0] - 2026-09-04

Initial release.

### Added

- Class-based `Sanitizer` definitions (`App\Sanitizers\{Model}Sanitizer`) resolved by naming convention, with an explicit `config/pii.php` mapping available as an override for models where convention doesn't apply — no explicit registration required for the common case.
- Support for all four PII field value-definition types in a `Sanitizer`: a static value, a closure receiving the current value, a Faker instance, and the model/row, a reference to an invokable class for logic reused across fields/models, and a Faker method shorthand string.
- Schema safety guard that rejects, at boot/registration time before any row is read or written, any declared field that is a foreign key, is referenced by another table's foreign key, or is the table's own primary key — naming the offending model and column in the error.
- Timestamp/audit columns (e.g. `created_at`, `updated_at`) are left untouched unless explicitly declared as a PII field.
- Uniqueness-preserving replacement generation for columns under a unique (or composite-unique) database constraint, guaranteeing no collisions against existing or newly-generated values within a run.
- Distribution-preserving replacement generation for categorical/enum-like columns, approximating the original value distribution rather than fully randomizing.
- Chunked read/update execution engine with automatic chunk sizing (with manual override), each chunk wrapped in its own database transaction so a mid-chunk failure leaves that chunk's rows unmodified, with failure reporting identifying which chunks did and did not complete.
- `--dry-run` mode reporting the columns and row counts that would change, without writing to the database.
- Idempotent sanitize runs — running the command again against an already-sanitized table completes without error or further destructive change.
- Single default database connection support for v1.
- Environment guard refusing to run outside `local`/`testing` unless `--force` is passed with explicit interactive confirmation.
- Per-environment configuration of active sanitizers, chunk size, and other run parameters via Laravel's standard config/environment mechanisms.
- `pii:sanitize` artisan command with a Laravel-idiomatic signature, `--help` output, non-zero exit codes on failure, and progress reporting for chunked operations.
- `PiiSanitizerServiceProvider` registering the command and a publishable `config/pii.php` via the standard `vendor:publish` mechanism.
- README covering installation, a minimal usage example (defining a `Sanitizer` and running the command), and the environment-guard and dry-run safety guarantees stated up front.
