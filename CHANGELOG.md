# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).
## [1.1.0] - 2026-09-02

### Added
- Publish a stable programmatic verification seam
## [1.0.1] - 2026-08-13

### Fixed
- Support the mariadb driver name
## [1.0.0] - 2026-08-06

### Added
- Project provenance fields as queryable columns

### Fixed
- Keep the http stack factory check opaque to phpstan

### Ci
- Restore the validate job required by branch protection
## [1.0.0-beta.3] - 2026-06-14

### Added
- Drop Laravel 11 support

### Build
- Consume fissible/attest from Packagist and support the Symfony 8 stack
## [1.0.0-beta.2] - 2026-06-13

### Fixed
- Type first() result as \stdClass for Laravel 11 PHPStan
- Create file-backed sqlite db before migrations on Laravel 11
## [1.0.0-beta.1] - 2026-06-13

### Added
- Support Laravel 13

### Changed
- Use shipped Fissible\Attest\Testing contract traits

### Ci
- Bump actions/checkout to v5 (Node 24)
## [0.4.0-alpha] - 2026-06-07

### Added
- Add generic JSONL importer

### Fixed
- Harden sqlite rollback after append failure
- Avoid stale SQLite transaction detection
## [0.3.0-alpha] - 2026-06-07

### Added
- Resolve anchor drivers from Laravel config
- Resolve verification header providers
- Resolve trusted verification keys
- Share artisan command result formatting
- Add anchor range runner service
- Add pending anchor upgrade service
- Add chain verification service
- Add bundle export and verify services
- Audit eloquent attest index drift
- Add anchor pending batch job
- Add attest anchor artisan command
- Add attest upgrade artisan command
- Add attest verify artisan command
- Add attest bundle artisan commands
- Add attest integrity audit command
## [0.2.1-alpha] - 2026-06-07

### Fixed
- Pin fissible/attest constraint so composer validate --strict passes
- Defensive inTransaction() checks in SqliteChainLocker
- Handle nested transactions + driver-aware makeStore
- Catch SQLite nested-txn from BEGIN IMMEDIATE + skip mysql context test
- Skip context-mismatch append test on non-sqlite drivers
- Varchar over char + drain stale txns in SqliteLocker tests
- Remove orphan brace from skipped SqliteChainLockerTest method
## [0.2.0-alpha] - 2026-06-06

### Added
- ChainIdHasher::hash() — 32-char sha256 prefix used by lockers
- Timestamp helper formats Y-m-d H:i:s.u UTC for all writes
- ChainLocker interface — strategy contract for per-driver locks
- SqliteChainLocker — raw-PDO BEGIN IMMEDIATE with busy_timeout
- MysqlChainLocker — GET_LOCK with \$acquired guard
- PostgresChainLocker — try_advisory_xact_lock polling with signed-int32 keys
- Migrations for attest_envelopes and attest_anchor_claims tables
- AttestEnvelope and AttestAnchorClaim with timestamps=false
- EloquentChainStore read paths — tail, readRange, readRawRange
- EloquentChainStore::append + EnvelopeRecorded post-commit dispatch
- EloquentAnchorClaimStore with atomic complete() and PHP-side cutoffs
- Full ServiceProvider with UTC session enforcement + Attest facade

### Ci
- Composer validate gate (full matrix lands in Chunk 4)
- Full DB matrix (sqlite/mysql8/pgsql16) x php (8.2/8.3/8.4)

