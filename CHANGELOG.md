# Changelog

## [Unreleased]

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
