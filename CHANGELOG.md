# Changelog

All notable changes to `shahirul22/laravel-pii-sanitizer` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
