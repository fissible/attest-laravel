# Changelog

## [Unreleased]

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
- Station-shaped updater audit fixture coverage proving the adapter supports Station's sidecar bridge without depending on Station code.

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
