# Changelog

## [1.0.1] — 2026-08-13

### Fixed
- **MariaDB connections can boot the package** ([#6](https://github.com/fissible/attest-laravel/issues/6)). Laravel reports a MariaDB connection under its own driver name, `mariadb` — `MariaDbConnection` extends `MySqlConnection`, but `getDriverName()` returns the configured driver string rather than the parent's. Both driver switches in `AttestServiceProvider` handled only `sqlite`, `mysql`, and `pgsql`, so registering the `ChainLocker` threw `RuntimeException: Unsupported DB driver for attest: mariadb` and the package could not be used on MariaDB at all.

  `forceUtc()` carried the same omission, and it is the more dangerous half: the locker threw first, so nothing reached it, but fixing only the locker would have unmasked it and replaced a loud boot failure with silently non-UTC envelope timestamps. Both arms are fixed together. MariaDB now shares `MysqlChainLocker`, which is correct rather than merely convenient — `GET_LOCK`/`RELEASE_LOCK` behave as that locker assumes, including holding several named locks on one connection from MariaDB 10.0.2 onward.

  Found by `fissible/verdict`, whose concurrency matrix runs MySQL, PostgreSQL, and MariaDB; the MariaDB leg failed 5 tests, all tracing into this provider.

### Changed
- CI runs a MariaDB 11 leg. The matrix covered `mysql8`, `pgsql16`, and `sqlite`, and a green MySQL leg says nothing about the `mariadb` driver string — which is precisely why this defect shipped in 1.0.0.

## [1.0.0] — 2026-08-05

First stable release, graduating the `1.0.0-beta` line. From here `fissible/attest-laravel` follows semantic versioning — the supported public API is the set of types marked `@api`. See [`STABILITY.md`](STABILITY.md).

### Added
- **Queryable `correlation`, `subject`, and `tenant` projection columns on `attest_envelopes`** ([#1](https://github.com/fissible/attest-laravel/issues/1)). These are first-class signed envelope fields, but until now they lived only inside `raw_envelope`, so "every envelope for correlation X" cost a full chain scan with a JSON decode per row — or forced a consumer to maintain its own side index table, which is exactly the mutable store this package exists to avoid.
- `AttestEnvelope::forCorrelation()`, `forSubject()`, and `forTenant()` query scopes, plus `AttestEnvelope::signed()` to decode the projected artifact. `forCorrelation()` and `forSubject()` order oldest-first by `created_at` with `envelope_id` as tiebreaker, and are deliberately not chain-scoped — a correlation id is assigned by the writing application, so an application sharding one chain per tenant still expects a single answer across chains.
- `attest:integrity:audit` now covers the three new columns. Blanking a projection column hides a row from correlation queries while the chain still verifies clean, because the signed bytes are untouched; the audit is what catches that, so it must.

### Changed
- **Every class in `src/` now carries the `@api` or `@internal` annotation STABILITY.md describes** — 17 public, 31 implementation detail, classified from that document's "The `@api` surface" section. The contract was written for 1.0.0 but had never been expressed in source, so the two could not be checked against each other.
- `Import\AlreadyImported` is `@internal`, not `@api`. It was listed as public while the contract was provisional, but it is control flow inside `GenericJsonlImporter`: the base throws it in the append callback to roll back a concurrent duplicate and catches it itself. A consumer never sees one, so freezing it would have frozen an implementation detail.
- The new migration backfills the projection from `raw_envelope` for envelopes written before it, in chunks of 500. Without it, existing evidence would stay invisible to every correlation query. Rows whose raw bytes cannot be decoded are skipped rather than aborting the schema change — that is a pre-existing integrity problem for `attest:integrity:audit` to surface, not something a backfill should mask.

## [1.0.0-beta.3] — 2026-06-13

### Removed
- **Dropped Laravel 11 support** — the supported range is now `^12 || ^13`. Laravel 11 has reached security-support EOL, and all 11.x releases are flagged by unpatched security advisories that Composer blocks by default. For a tamper-evidence package, claiming support for an EOL, advisory-flagged framework is inappropriate.

### Changed
- Consume `fissible/attest` from Packagist: dropped the local `path` repository and the CI sibling-checkout that fed it, and tightened `minimum-stability` to `stable`.
- Migrated to PHPStan `^2.0` so the Symfony 8 stack — pulled in via `fissible/attest` 1.1.1 and used by downstream Laravel 13 apps — analyses cleanly. (PHPStan 1.x could not reflect Symfony 8's `Command` class and falsely reported inherited `SUCCESS`/`FAILURE` constants as undefined.)

## [1.0.0-beta.2] — 2026-06-13

Supersedes 1.0.0-beta.1, whose CI was red on the Laravel 11 lane (two L11-only defects surfaced once the matrix pinned each major explicitly). Functionally identical otherwise. Use this as the soak target.

### Fixed
- PHPStan failure on Laravel 11: `Query\Builder::first()` is typed `object|null` by L11's stubs (rather than `\stdClass|null`), so the dynamic property reads in `EloquentAnchorClaimStore::completeClaim()` were rejected. Annotated the result to its real runtime shape.
- Concurrency test error on Laravel 11: `parent::setUp()` migrates a file-backed sqlite database that Laravel 11 (unlike 12+) does not auto-create, throwing before the in-CI skip guard. The test now touches the file when missing.

## [1.0.0-beta.1] — 2026-06-13

First `1.0.0` beta, cut for consumer soak (Mesabit). The public API is considered frozen for the duration of the soak; the formal stability guarantee lands with `1.0.0`.

### Added
- **Laravel 13 support** (`illuminate/* ^13.0`, `orchestra/testbench ^11.0`). Verified against Laravel 13.15.0 / testbench 11.1 with no source changes — Laravel 13 is a minimal-breaking-changes release and the adapter only touches stable Eloquent/console/queue/event contracts.
- CI matrix now pins each supported Laravel major (11/12/13) via its testbench major (`^9`→L11, `^10`→L12, `^11`→L13) so the full `^11 || ^12 || ^13` range is exercised at both ends rather than floating to the newest. Laravel 13 requires PHP `^8.3`, so PHP 8.2 is paired only with L11/L12.

### Changed
- Require `fissible/attest ^1.1` (was `^1.0`) and consume the shipped `Fissible\Attest\Testing\*` storage contract test traits instead of copy-pasted ports. The adapter's `EloquentChainStore` and `EloquentAnchorClaimStore` are now tested against the single canonical core contract, so drift between core and the adapter is impossible.

### Removed
- The `tests/Contract/*` trait ports, superseded by the shipped core traits.

## [0.4.1-alpha] — 2026-06-10

### Changed
- Require `fissible/attest ^1.0` (was `^0.4.0-alpha`). The adapter now targets the stable attest 1.0 core, verified against attest 1.0.0 (181 tests passing, PHPStan clean). No adapter API changes. Note: attest 1.0.0 changed the canonical binary wrapper to `{"$binary": …}`, but this adapter records no binary payloads of its own — consumers are unaffected unless they pass `Binary` payloads directly.

## [0.4.0-alpha] — 2026-06-07

### Added
- `GenericJsonlImporter` abstract base for replaying external JSONL into attest chains with lazy file reading, source provenance payload wrapping, fail-fast or continue-on-error diagnostics, and duplicate-safe marker handling.
- `EloquentImportMarkerTrait` plus `attest_import_markers` migration for idempotent imports keyed by importer/source namespace and content hash.
- Import result, failure, context, options, and exception value types.
- Updater-audit fixture coverage proving the adapter supports a consumer-side sidecar bridge over an existing JSONL log without depending on that consumer's code.

## [0.3.0-alpha] — 2026-06-07

### Added
- Artisan command surfaces for anchoring, OpenTimestamps upgrade, chain verification, bundle export, bundle verification, and Eloquent index integrity audit.
- `AnchorPendingBatch` queue job for scheduler-friendly anchoring without package-level auto-scheduling.
- Resolver services for anchor drivers, header providers, and trusted keys, including command/config merging.
- `IntegrityAudit` service and `attest:integrity:audit` command for detecting drift in Eloquent read-side index columns without treating those indexes as verifier trust input.
- Bundle export/verify service and commands using the core bundle APIs while keeping claimed bundle keys informational only.
- Optional Guzzle integration for OpenTimestamps calendar and Bitcoin header-provider commands via `require-dev` and Composer suggestions, not hard runtime requirements.

## [0.2.1-alpha] — 2026-06-06

### Fixed
- Pin the `fissible/attest` development path-repository version so `composer validate --strict` passes in CI.
- Harden `SqliteChainLocker` around nested transaction state from Testbench and explicit `BEGIN IMMEDIATE`.
- Use `VARCHAR` identifier columns in migrations instead of fixed-width `CHAR`, avoiding PostgreSQL trailing-space padding on `envelope_id` and `anchor_id`.
- Make the Eloquent chain-store contract wrapper resolve the correct driver-specific store.
- Mark CI-fragile multi-session lock contention and forked SQLite concurrency edge tests as skipped with documented coverage rationale.

## [0.2.0-alpha] — 2026-06-06

### Added
- `EloquentChainStore` implementing both `Fissible\Attest\Chain\ChainStore` and `RawChainStore`.
- `EloquentAnchorClaimStore` implementing `Fissible\Attest\Anchor\AnchorClaimStore` with atomic `complete()` (conditional UPDATE with re-read for conflict detection).
- Per-driver locking strategies in `src/Stores/Locking/`: `SqliteChainLocker` (raw-PDO `BEGIN IMMEDIATE` + `PRAGMA busy_timeout`), `MysqlChainLocker` (`GET_LOCK` with `$acquired` guard), `PostgresChainLocker` (`pg_try_advisory_xact_lock` polling with signed-int32 keys, accepts PDO boolean-ish return values).
- `Fissible\AttestLaravel\Support\Timestamp` helper formats `Y-m-d H:i:s.u` UTC so microseconds survive Laravel's grammar binding across all three drivers.
- `Fissible\AttestLaravel\Support\ChainIdHasher` centralizes the `substr(sha256(chain_id), 0, 32)` lock-key derivation.
- Migrations for `attest_envelopes` and `attest_anchor_claims`, dialect-correct on SQLite/MySQL/Postgres.
- Eloquent models `AttestEnvelope` and `AttestAnchorClaim` with `$timestamps = false`.
- `AttestServiceProvider` binds the strategy-selected `ChainLocker`, both stores, the `Signer`, and the `AttestRegistry`. Forces UTC session timezone on the configured attest connection on every connection establishment.
- `Attest` facade and `AttestRegistry::chain($chainId)` — returns a fresh `EvidenceChain` per call (no per-chain singleton cache).
- `EnvelopeRecorded` event, dispatched after the locker's transaction commits.
- `tests/Contract/ChainStoreContractTests.php` and `tests/Contract/AnchorClaimStoreContractTests.php` ported from core.
- `tests/Concurrency/EloquentConcurrencyTest.php` — `pcntl_fork` 8 workers × 100 envelopes per chain.
- CI matrix: PHP 8.2/8.3/8.4 × SQLite / MySQL 8 / Postgres 16; macOS additionally for SQLite.

## [0.1.0-alpha] — 2026-05-25

Initial scaffold.
