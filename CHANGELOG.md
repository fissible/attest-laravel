# Changelog

## [Unreleased]

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
