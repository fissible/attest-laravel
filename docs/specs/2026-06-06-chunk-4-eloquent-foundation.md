# fissible/attest-laravel — Chunk 4 Design Spec: Eloquent Foundation

**Date:** 2026-06-06
**Status:** Design, pre-implementation
**Scope:** First implementation chunk for `fissible/attest-laravel` after the Chunk 0 scaffold. Builds the Eloquent storage foundation that subsequent adapter chunks (Artisan commands, queue jobs, generic importer) sit on top of.

---

## 1. Summary

`fissible/attest-laravel` is the Laravel adapter for the framework-agnostic `fissible/attest` core library. Chunk 4 ships the load-bearing pieces: two Eloquent-backed implementations of the core storage contracts (`ChainStore` + `RawChainStore`, `AnchorClaimStore`), Laravel migrations that emit dialect-correct DDL for SQLite / MySQL 8 / PostgreSQL 16, a service provider that auto-discovers under Laravel 11 and 12, a minimal config file, an `Attest` facade for ergonomic chain access, and Eloquent models for query convenience. Per-driver locking dispatches at runtime via a strategy interface. The event surface is limited to `EnvelopeRecorded`, dispatched after the write transaction commits.

Out of this chunk: Artisan commands wrapping the CLI surface, the `AnchorPendingBatch` queueable job, `GenericJsonlImporter`, INDEX_DRIFT detection, paratest-based concurrency. Those land in Chunks 5+.

## 2. Non-Goals (this chunk)

- Artisan command wrappers around the `attest` CLI surface (deferred to Chunk 5).
- Queue jobs and scheduler bindings (deferred to Chunk 5).
- `GenericJsonlImporter` and `EloquentImportMarkerTrait` (deferred to Chunk 6).
- INDEX_DRIFT detection in the core verifier — the core `Verifier` has no extension point for Eloquent-side indexed columns. The check belongs alongside an integrity-audit Artisan command in Chunk 5.
- `brianium/paratest`. Concurrency uses `pcntl_fork`, matching core's existing pattern.
- Tenancy scoping, authorization gates, Filament resources — these are the consumer's job per core spec §14.
- HSM / KMS signer adapters (v1.1+).

## 3. Architecture Overview

```
┌──────────────────────┐     Attest::chain('tenant:5')      ┌──────────────────┐
│ Attest facade        │ ──────────────────────────────────▶│ AttestRegistry   │
└──────────────────────┘                                    └──────────────────┘
                                                                       │
                                                              EvidenceChain
                                                                       │
                                                                       ▼
            ┌──────────────────────────┐  ┌──────────────────────────┐
            │ EloquentChainStore        │  │ EloquentAnchorClaimStore │
            │   (ChainStore + Raw…)     │  │                          │
            └──────────────────────────┘  └──────────────────────────┘
                       │
                       ▼
            ┌──────────────────────────┐
            │ ChainLocker (strategy)    │
            ├──────────────────────────┤
            │ SqliteChainLocker         │
            │ MysqlChainLocker          │
            │ PostgresChainLocker       │
            └──────────────────────────┘
                       │
                       ▼
                   PDO writes
                  + transaction
```

One `EloquentChainStore` class; three locking strategies. Per-driver dispatch happens once in the service provider when resolving the locker from the configured connection's driver name. The store always owns writes and always validates the `AppendContext` invariants from core. Models are read-side query objects; they are never the write path.

## 4. Package Layout

```
src/
├── AttestServiceProvider.php
├── Facades/
│   └── Attest.php
├── Support/
│   ├── AttestRegistry.php          — Attest::chain(...) implementation
│   └── ChainIdHasher.php           — substr(sha256(chain_id), 0, 32) helper
├── Stores/
│   ├── EloquentChainStore.php      — ChainStore + RawChainStore
│   ├── EloquentAnchorClaimStore.php
│   └── Locking/
│       ├── ChainLocker.php         (interface)
│       ├── SqliteChainLocker.php
│       ├── MysqlChainLocker.php
│       └── PostgresChainLocker.php
├── Models/
│   ├── AttestEnvelope.php
│   └── AttestAnchorClaim.php
└── Events/
    └── EnvelopeRecorded.php
database/migrations/
├── 2026_06_06_000001_create_attest_envelopes_table.php
└── 2026_06_06_000002_create_attest_anchor_claims_table.php
config/
└── attest.php
tests/
├── TestCase.php                    (extends Orchestra\Testbench\TestCase)
├── Contract/
│   ├── ChainStoreContractTests.php — ported from core
│   └── AnchorClaimStoreContractTests.php — ported from core
├── Stores/
│   ├── EloquentChainStoreTest.php
│   ├── EloquentRawChainStoreTest.php
│   └── EloquentAnchorClaimStoreTest.php
├── Locking/
│   ├── SqliteChainLockerTest.php
│   ├── MysqlChainLockerTest.php
│   └── PostgresChainLockerTest.php
├── Concurrency/
│   └── EloquentConcurrencyTest.php (pcntl_fork, 8×100)
├── Models/
│   └── AttestEnvelopeTest.php
└── AttestServiceProviderTest.php
```

The contract test traits are ported from core's `tests/Chain/ChainStoreContractTests.php` and `tests/Anchor/AnchorClaimStoreContractTests.php` because Composer does not autoload upstream `tests/` paths. A v1.0 TODO is to extract these into a shipped `Fissible\Attest\Testing\*` namespace inside core so adapter packages can require them as production code. For this chunk, port with attribution.

## 5. Configuration

`config/attest.php`:

```php
<?php

return [
    /**
     * Which Laravel database connection holds the attest tables.
     * null falls back to config('database.default'). Operators commonly
     * want a dedicated connection (separate schema or even separate
     * server) for the evidence chain.
     */
    'connection' => env('ATTEST_CONNECTION'),

    /**
     * Total wall-clock seconds the locker will wait before throwing
     * ChainLockUnavailable.
     */
    'lock_timeout_seconds' => 10,

    /**
     * Postgres locker polling interval (microseconds) for
     * pg_try_advisory_xact_lock. Lower values reduce contention latency
     * at the cost of more SELECT calls per failed acquisition.
     */
    'postgres_lock_poll_us' => 50_000,  // 50ms

    /**
     * Ed25519 signing material. Both env vars are required when the
     * Attest facade is used; the registry will throw a clear error if
     * either is missing at first call.
     */
    'signing_key' => [
        'seed_env'   => 'ATTEST_SIGNING_KEY_SEED',   // base64 32-byte seed
        'key_id_env' => 'ATTEST_SIGNING_KEY_ID',
    ],

    /**
     * Default anchor driver for any tooling layered on this adapter.
     * Chunk 4 does not ship anchor automation; this is read by future
     * Chunk 5 Artisan commands.
     */
    'default_anchor_driver' => env('ATTEST_DEFAULT_DRIVER', 'local-only'),

    /**
     * AnchorClaim TTL — reclaimable after this many seconds of being
     * incomplete. Matches core spec §8.4 default.
     */
    'claim_ttl_seconds' => 3600,
];
```

`AttestServiceProvider::register()` reads `config('attest.connection') ?? config('database.default')`, looks up the driver name via `DB::connection($name)->getDriverName()`, and binds the matching `ChainLocker` implementation. `EloquentChainStore` and `EloquentAnchorClaimStore` are bound as singletons against the resolved connection.

## 6. Database Schema

Two tables, written via Laravel's Schema builder so DDL is dialect-correct. Logical schema shown in MySQL-ish notation; Laravel migrations emit the right thing per driver.

### 6.1 `attest_envelopes`

```sql
CREATE TABLE attest_envelopes (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    chain_id VARCHAR(191) NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    envelope_id CHAR(26) NOT NULL,
    prev_hash VARCHAR(80) NULL,
    self_hash VARCHAR(80) NOT NULL,
    key_id VARCHAR(191) NOT NULL,
    type VARCHAR(191) NOT NULL,
    raw_envelope MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP(6) NOT NULL,
    UNIQUE KEY (chain_id, sequence),
    UNIQUE KEY (envelope_id),
    INDEX (chain_id, type, created_at)
);
```

Migration via `Schema::create('attest_envelopes', function (Blueprint $t) { … })` using:

- `$t->id();` for the surrogate PK.
- `$t->string('chain_id', 191);`
- `$t->unsignedBigInteger('sequence');`
- `$t->char('envelope_id', 26);`
- `$t->string('prev_hash', 80)->nullable();`
- `$t->string('self_hash', 80);`
- `$t->string('key_id', 191);`
- `$t->string('type', 191);`
- `$t->mediumText('raw_envelope');`
- `$t->timestampTz('created_at', precision: 6);` — TIMESTAMPTZ on Postgres, TIMESTAMP(6) on MySQL/SQLite via Laravel's dialect mapping.
- Unique on `(chain_id, sequence)` and `envelope_id`; index on `(chain_id, type, created_at)`.

**`raw_envelope` is `mediumText`, never `json`.** JSON column types normalize whitespace and key order; canonical bytes must be byte-preserved for hash verification.

### 6.2 `attest_anchor_claims`

```sql
CREATE TABLE attest_anchor_claims (
    anchor_id CHAR(64) PRIMARY KEY,
    chain_id VARCHAR(191) NOT NULL,
    from_seq BIGINT UNSIGNED NOT NULL,
    to_seq BIGINT UNSIGNED NOT NULL,
    driver VARCHAR(64) NOT NULL,
    claimed_by VARCHAR(255) NOT NULL,
    claimed_at TIMESTAMP(6) NOT NULL,
    completed_at TIMESTAMP(6) NULL,
    completed_envelope_id CHAR(26) NULL,
    INDEX (chain_id, completed_at)
);
```

`claim()` uses `INSERT … ON CONFLICT DO NOTHING` on PG / `INSERT IGNORE` on MySQL / `INSERT OR IGNORE` on SQLite, returning `true` when affected rows > 0. Laravel's query builder exposes `insertOrIgnore()` which compiles to the correct dialect form on each driver.

## 7. Per-Driver Locking

```php
interface ChainLocker
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws ChainLockUnavailable when the lock cannot be acquired within timeout
     */
    public function withChainLock(string $chainId, callable $work): mixed;
}
```

Implementations live in `src/Stores/Locking/`. All three normalize the chain ID via `ChainIdHasher::hash($chainId)` which returns `substr(sha256($chainId), 0, 32)`. All three throw the core `Fissible\Attest\Chain\ChainLockUnavailable` exception on failure.

### 7.1 `SqliteChainLocker`

```php
final class SqliteChainLocker implements ChainLocker
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function withChainLock(string $chainId, callable $work): mixed
    {
        $pdo = $this->connection->getPdo();
        // Laravel's $connection->beginTransaction()/commit() starts a
        // deferred transaction and increments Laravel's transactionLevel.
        // We want BEGIN IMMEDIATE explicitly via PDO so the write lock is
        // held up-front, without confusing Laravel's transaction counter.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
```

**SQLite's write lock is database-wide, not per-chain.** There is no finer-grained lock available without WAL+busy-timeout, and even those still serialize writers globally. SQLite is appropriate for single-host / single-writer deployments — application servers, dev environments, single-instance jobs. Multi-writer concurrent throughput on a shared chain requires MySQL or Postgres. Document this in the README and in the package's UPGRADE notes.

`SQLITE_BUSY` from a contending writer surfaces as a `\PDOException`; convert it to `ChainLockUnavailable` if the message matches `database is locked`.

### 7.2 `MysqlChainLocker`

```php
public function withChainLock(string $chainId, callable $work): mixed
{
    $lockName = 'attest:chain:' . ChainIdHasher::hash($chainId);
    $acquired = false;
    try {
        $row = $this->connection->selectOne(
            'SELECT GET_LOCK(?, ?) AS got',
            [$lockName, $this->timeoutSeconds],
        );
        $acquired = $row !== null && (int) $row->got === 1;
        if (! $acquired) {
            // 0 = timeout, NULL = error; both surface as ChainLockUnavailable.
            throw new ChainLockUnavailable($chainId);
        }
        $this->connection->beginTransaction();
        try {
            $result = $work();
            $this->connection->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    } finally {
        if ($acquired) {
            $this->connection->statement('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
}
```

The `$acquired` flag is critical: `GET_LOCK` returns `1` on success, `0` on timeout, `NULL` on error. `RELEASE_LOCK` must only run when we actually hold the lock. Spec §7.3 calls this out explicitly.

### 7.3 `PostgresChainLocker`

```php
public function withChainLock(string $chainId, callable $work): mixed
{
    // pg_advisory_xact_lock() is *blocking*; that would deadlock the
    // ChainLockUnavailable contract. We use pg_try_advisory_xact_lock()
    // in a short poll loop with a configurable total timeout.
    //
    // The transaction MUST begin before the first lock attempt, because
    // _xact_lock() ties the lock to the transaction. Timeout rolls
    // back that transaction.
    $hash = hash('sha256', $chainId, binary: true);
    // Two 32-bit signed integers from the first 8 bytes.
    // unpack('N', …) gives an unsigned 32-bit value; coerce to signed
    // because Postgres int4 is signed, and Laravel binds PHP ints
    // through libpq as int4 when in range.
    [$k1Unsigned, $k2Unsigned] = array_values(unpack('N2', substr($hash, 0, 8)));
    $k1 = $this->toSignedInt32($k1Unsigned);
    $k2 = $this->toSignedInt32($k2Unsigned);

    $this->connection->beginTransaction();
    try {
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (true) {
            $row = $this->connection->selectOne(
                'SELECT pg_try_advisory_xact_lock(?, ?) AS got',
                [$k1, $k2],
            );
            if ($row !== null && $row->got === true) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $this->connection->rollBack();
                throw new ChainLockUnavailable($chainId);
            }
            usleep($this->pollUs);
        }
        $result = $work();
        $this->connection->commit();
        return $result;
    } catch (\Throwable $e) {
        if ($this->connection->transactionLevel() > 0) {
            $this->connection->rollBack();
        }
        throw $e;
    }
}

private function toSignedInt32(int $u): int
{
    return $u >= 0x80000000 ? $u - 0x100000000 : $u;
}
```

Two notes:

- The **transaction must begin before the polling loop**. `pg_try_advisory_xact_lock()` outside a transaction would acquire and immediately release. Inside a transaction it holds until commit or rollback.
- Both keys are **signed 32-bit integers**. Postgres's `int4` is signed; values above `0x7FFFFFFF` must be wrapped to their negative counterparts. The conversion is straightforward but easy to get wrong; the `toSignedInt32()` helper is centralized.

## 8. EloquentChainStore Semantics

Implements both `ChainStore` (Chunk 1) and `RawChainStore` (Chunk 2).

### 8.1 Read paths

- `tail($chainId)`: `SELECT raw_envelope FROM attest_envelopes WHERE chain_id = ? ORDER BY sequence DESC LIMIT 1` → `EnvelopeCodec::decodeSigned($row->raw_envelope)`.
- `readRange($chainId, $fromSeq, $toSeq=null)`: streams rows with `cursor()` to avoid loading the whole range, decodes each `raw_envelope` to a `SignedEnvelope`.
- `readRawRange($chainId, $fromSeq, $toSeq=null)`: same query, yields `raw_envelope` bytes verbatim without decoding.

**Decode only `raw_envelope`.** The indexed columns (`sequence`, `key_id`, `type`, `prev_hash`, `self_hash`, `envelope_id`) are read-side metadata for query convenience and future INDEX_DRIFT diagnostics. They are NOT used to materialize envelope state — that always comes from the canonical bytes, so byte-identity verification at `Verifier` still works.

- `listChains()`: `SELECT DISTINCT chain_id FROM attest_envelopes`, yields strings.
- `exists($chainId)`: `SELECT 1 FROM attest_envelopes WHERE chain_id = ? LIMIT 1`.

### 8.2 Write path (`append`)

```php
public function append(string $chainId, callable $buildAndSign): SignedEnvelope
{
    return $this->locker->withChainLock($chainId, function () use ($chainId, $buildAndSign) {
        $tail = $this->tail($chainId);
        $context = new AppendContext(
            chainId: $chainId,
            sequence: $tail === null ? 1 : $tail->envelope->seq + 1,
            prevHash: $tail?->selfHash(),
            timestampIso8601: $this->monotonicTimestamp($tail),
        );

        $signed = $buildAndSign($context);
        $this->validateContext($context, $signed);

        $bytes = $signed->signedCanonicalBytes();
        $this->connection->table('attest_envelopes')->insert([
            'chain_id'     => $chainId,
            'sequence'     => $context->sequence,
            'envelope_id'  => $signed->envelope->id,
            'prev_hash'    => $signed->envelope->prevHash,
            'self_hash'    => $signed->selfHash(),
            'key_id'       => $signed->envelope->keyId,
            'type'         => $signed->envelope->type,
            'raw_envelope' => $bytes,
            'created_at'   => $signed->envelope->ts,  // from envelope, NOT DB default
        ]);

        // EnvelopeRecorded is dispatched AFTER the locker commits the
        // transaction. The locker's $work returns to it before commit;
        // the event therefore queues here and fires after withChainLock
        // returns. See §11.
        $this->pendingEvents[] = new EnvelopeRecorded($chainId, $signed);
        return $signed;
    });
}
```

Two things to note:

- `created_at` is populated from the **envelope's `ts` field**, not the DB default. Microsecond precision survives because the column is `TIMESTAMP(6)` / `TIMESTAMPTZ(6)` and Laravel binds the ISO-8601 string through PDO.
- The event dispatch sits outside the transaction. See §11.

`monotonicTimestamp()` enforces `max(now, tail.ts + 1ms)` exactly as `FileChainStore` does. `validateContext()` raises `ContextMismatch` if the callback returned an envelope whose chain/seq/prev_hash/ts don't match the context.

## 9. EloquentAnchorClaimStore

Implements `AnchorClaimStore` (Chunk 2).

- `claim($anchorId, $details)`: `$count = DB::table('attest_anchor_claims')->insertOrIgnore([...]); return $count > 0;`
- `release($anchorId)`: `DELETE FROM attest_anchor_claims WHERE anchor_id = ? AND completed_at IS NULL`.
- `complete($anchorId, $envelopeId)`: `UPDATE attest_anchor_claims SET completed_at = NOW(6), completed_envelope_id = ? WHERE anchor_id = ?`. Idempotent: if already completed with the same envelope_id, no-op; with a different envelope_id, throw to surface the inconsistency.
- `reclaimExpired($ttlSeconds)`: `SELECT * FROM attest_anchor_claims WHERE completed_at IS NULL AND claimed_at < (NOW(6) - INTERVAL <ttl>)`. Yields `AnchorClaim` value objects.

## 10. Models

```php
namespace Fissible\AttestLaravel\Models;

class AttestEnvelope extends Model
{
    protected $table = 'attest_envelopes';
    public $timestamps = false;     // store writes created_at explicitly; no updated_at exists
    protected $guarded = [];
    protected $casts = [
        'sequence'   => 'integer',
        'created_at' => 'datetime',
    ];
}
```

```php
class AttestAnchorClaim extends Model
{
    protected $table = 'attest_anchor_claims';
    public $timestamps = false;
    protected $primaryKey = 'anchor_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'from_seq'    => 'integer',
        'to_seq'      => 'integer',
        'claimed_at'  => 'datetime',
        'completed_at' => 'datetime',
    ];
}
```

`$timestamps = false` is critical: the schema has only `created_at` (populated explicitly by the store from the envelope's `ts`), no `updated_at`. Leaving Eloquent timestamps on would cause it to try to fill both columns, producing SQL errors against MySQL/Postgres and silent corruption of the envelope's authoritative `ts`.

## 11. Service Provider, Facade, and Registry

`AttestServiceProvider`:

- `register()`:
  - Merges `config/attest.php` into the consumer's config.
  - Binds `ChainLocker` based on the active connection's driver name.
  - Binds `EloquentChainStore` and `EloquentAnchorClaimStore` as singletons.
  - Binds `Fissible\Attest\Signing\Signer` from the configured env vars (lazily — failure surfaces on first use, not on app boot).
  - Binds `AttestRegistry` singleton.
- `boot()`:
  - Publishes config and migrations: `vendor:publish --tag=attest-config`, `vendor:publish --tag=attest-migrations`.
  - Auto-loads migrations from the package's `database/migrations/` (`$this->loadMigrationsFrom(...)`) so consumers don't need to publish to start using.

`Attest` facade returns `AttestRegistry`. Public surface:

```php
Attest::chain(string $chainId): EvidenceChain
Attest::store(): ChainStore
Attest::claimStore(): AnchorClaimStore
```

`AttestRegistry::chain($chainId)` builds and caches an `EvidenceChain::open($store, $chainId, $signer)` per chain.

## 12. Events

```php
final readonly class EnvelopeRecorded
{
    public function __construct(
        public string $chainId,
        public SignedEnvelope $signed,
    ) {}
}
```

Fired **only after** the DB transaction commits. Two implementation choices:

1. Register a `DB::afterCommit(...)` callback inside the locker's `$work`.
2. Have `EloquentChainStore::append()` accumulate events in a private array inside `$work`, then dispatch them after the locker returns (i.e., after commit).

**Use option 2.** It's simpler, doesn't depend on Laravel's afterCommit machinery being available for non-Eloquent events, and the test surface is straightforward (assert the event fires after the row is visible in a subsequent query). The store's `$pendingEvents` is per-call (instance state only matters within the single `append()` call).

`DB::afterCommit()` would also work, but it requires the connection to be inside a transaction known to Laravel — which Postgres and MySQL paths are, but the SQLite raw-PDO path is not. Option 2 is uniform across drivers.

No events fire on read paths, on rollback, or on validation failure.

## 13. Test Strategy

### 13.1 Test base

```php
namespace Fissible\AttestLaravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [\Fissible\AttestLaravel\AttestServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        $app['config']->set('database.default', $driver);
        // ... per-driver host/port/db from env vars
    }
}
```

### 13.2 Contract suites

Port the core's `tests/Chain/ChainStoreContractTests.php` and `tests/Anchor/AnchorClaimStoreContractTests.php` into `tests/Contract/` in this package. Both traits exercise the contract semantics (sequence monotonicity, `prev_hash` linking, `AppendContext` mismatch detection, claim/release/complete/reclaim cycles) against any concrete implementation. `EloquentChainStoreTest` and `EloquentAnchorClaimStoreTest` use these traits.

**Attribution comment** at the top of each ported file references the upstream source. TODO comment notes the v1.0 plan to extract these into `Fissible\Attest\Testing\*` in core and re-require them here.

### 13.3 Per-driver locker tests

Single-driver unit tests under `tests/Locking/`. Each test exercises:

- Successful acquisition and release.
- Timeout (a second connection holding the lock).
- Exception propagation from `$work` (lock released; transaction rolled back).
- `ChainLockUnavailable` raised with the right chain ID in the message.

For SQLite specifically, document the global-lock semantics in the test docstring.

### 13.4 Concurrency

`tests/Concurrency/EloquentConcurrencyTest.php` mirrors core's `FileChainStoreConcurrencyTest.php`: `pcntl_fork` 8 workers × 100 envelopes against the same `chain_id`, assert linear chain after join. Skip on driver = SQLite if running in CI on macOS (forking inside the testbench harness is brittle); use Linux runners for MySQL/Postgres rounds. **No `brianium/paratest`** — deferred.

### 13.5 Byte-identity

`EloquentRawChainStoreTest` writes envelopes via the store, then reads via `readRawRange()` and asserts the bytes equal `signedCanonicalBytes()` byte-for-byte. This is the load-bearing test that `raw_envelope` survives the round-trip unaltered (no whitespace normalization, no key reordering, no encoding drift).

### 13.6 Service provider

- Config publishing path is correct.
- Migrations auto-load.
- Container resolves `ChainStore`, `AnchorClaimStore`, `Signer`, `AttestRegistry`.
- Facade `Attest::chain('test')->record(...)` works end-to-end against SQLite.

## 14. CI Matrix

Per core spec §17. `.github/workflows/ci.yml`:

- Matrix axes:
  - PHP: 8.2, 8.3, 8.4
  - DB: `sqlite`, `mysql8`, `pgsql16`
  - OS: `ubuntu-latest` for all; `macos-latest` additionally for SQLite to exercise the macOS PDO-sqlite path.
- MySQL 8 and PostgreSQL 16 via GH Actions service containers; SQLite via the PHP extension (in-memory or file in tmpdir).
- Steps: `composer validate --strict`, `composer install`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpunit --colors=always`.
- `DB_CONNECTION` env var selects the driver in `tests/TestCase.php::defineEnvironment`.

The reusable `fissible/.github/.github/workflows/release.yml` wires the tag → GitHub Release step (same as `fissible/attest`).

## 15. Done Criteria

- All migrations apply cleanly against SQLite, MySQL 8, PostgreSQL 16 (verified in CI matrix).
- `vendor/bin/phpunit` passes locally and across the full CI matrix.
- `vendor/bin/phpstan analyse` clean.
- `Attest::chain('tenant:5')->record('app.event', [...])` produces a row whose `raw_envelope` round-trips byte-identically through `Verifier::verifyChain()` against all three drivers.
- Concurrency test passes with 8 workers × 100 envelopes on at least MySQL and Postgres; SQLite test allowed to assert global serialization rather than per-chain concurrency.
- `EnvelopeRecorded` event fires exactly once per successful `append()`, after the transaction commits, and never on rollback.
- README has install + migrate + config + a 50-line end-to-end example using the facade.
- Composer auto-discovery loads `AttestServiceProvider` and the `Attest` facade under Laravel 11 and 12.
- Tag `v0.2.0-alpha` (attest-laravel's own VERSION; bumps the Chunk 0 scaffold's `0.1.0-alpha`).

## 16. References

- `fissible/attest` core spec, `~/lib/fissible/station/docs/superpowers/specs/2026-05-25-fissible-attest-design.md`, especially §7.3, §8.4, §14, §17.
- `fissible/attest` Chunk 0+1 plan, `~/lib/fissible/station/docs/superpowers/plans/2026-05-25-fissible-attest-chunks-0-1.md`.
- MySQL named locks (session scope): https://dev.mysql.com/doc/mysql/8.0/en/locking-functions.html
- PostgreSQL advisory locks (xact scope): https://www.postgresql.org/docs/current/functions-admin.html
- SQLite `BEGIN IMMEDIATE`: https://www.sqlite.org/lang_transaction.html
- Laravel package development (Testbench, auto-discovery): https://laravel.com/docs/packages
