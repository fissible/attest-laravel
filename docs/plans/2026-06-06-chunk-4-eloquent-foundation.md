# fissible/attest-laravel — Chunk 4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the Eloquent foundation for `fissible/attest-laravel`: two store implementations (`EloquentChainStore`, `EloquentAnchorClaimStore`), per-driver locking strategy (SQLite raw-PDO, MySQL `GET_LOCK`, Postgres `pg_try_advisory_xact_lock`), migrations, models, service provider, facade, and a CI matrix that exercises SQLite + MySQL 8 + PostgreSQL 16.

**Architecture:** One `EloquentChainStore` class; three `ChainLocker` strategies dispatched at service-provider boot. Writes happen inside the locker's transaction; events accumulate in a closure-local array dispatched after commit. All timestamps format to `Y-m-d H:i:s.u` UTC via a single `Timestamp` helper so microseconds survive across drivers. DB session timezone is forced to UTC at connection boot. `complete()` on the claim store is an atomic conditional UPDATE; the losing writer re-reads to distinguish idempotent retry from real conflict.

**Tech Stack:** PHP 8.2+, Laravel 11/12 (`illuminate/support`, `illuminate/database`), `orchestra/testbench`, PHPUnit 11, PHPStan 1.x. SQLite via `pdo_sqlite`, MySQL 8 + Postgres 16 via GH service containers. Depends on `fissible/attest` core ≥ `v0.4.1-alpha`.

**Spec:** `docs/specs/2026-06-06-chunk-4-eloquent-foundation.md`.

**Tag at completion:** `v0.2.0-alpha` (this package's second tag; bumps the Chunk 0 scaffold's `0.1.0-alpha`).

---

## Assumed from prior chunks

From `fissible/attest` ≥ `v0.4.1-alpha`:

- `Fissible\Attest\Envelope\{EvidenceEnvelope, SignedEnvelope, EnvelopeCodec, PayloadValidator, Binary, InvalidPayload}` — including post-Chunk-2.5 fixes (Binary canonical stand-in, `MAX_SIGNED_ENVELOPE_BYTES`).
- `Fissible\Attest\Chain\{ChainStore, RawChainStore, AppendContext, EvidenceChain, ContextMismatch, ChainLockUnavailable}`.
- `Fissible\Attest\Signing\{KeyPair, Signer, SodiumSigner}`.
- `Fissible\Attest\Anchor\{AnchorClaim, AnchorClaimStore}`.
- Core contract tests at `tests/Chain/ChainStoreContractTests.php` and `tests/Anchor/AnchorClaimStoreContractTests.php` (ported by value in Task 4.12).

If any are missing, escalate before extending this plan.

---

## File Structure

### New files

```
src/AttestServiceProvider.php                              (Task 4.0 stub; Task 4.13 full)
src/Support/ChainIdHasher.php                              (Task 4.1)
src/Support/Timestamp.php                                  (Task 4.2)
src/Stores/Locking/ChainLocker.php                         (Task 4.3)
src/Stores/Locking/SqliteChainLocker.php                   (Task 4.4)
src/Stores/Locking/MysqlChainLocker.php                    (Task 4.5)
src/Stores/Locking/PostgresChainLocker.php                 (Task 4.6)
database/migrations/2026_06_06_000001_create_attest_envelopes_table.php   (Task 4.7)
database/migrations/2026_06_06_000002_create_attest_anchor_claims_table.php (Task 4.7)
src/Models/AttestEnvelope.php                              (Task 4.8)
src/Models/AttestAnchorClaim.php                           (Task 4.8)
src/Stores/EloquentChainStore.php                          (Tasks 4.9, 4.10)
src/Events/EnvelopeRecorded.php                            (Task 4.10)
src/Stores/EloquentAnchorClaimStore.php                    (Task 4.11)
src/Support/AttestRegistry.php                             (Task 4.13)
src/Facades/Attest.php                                     (Task 4.13)
config/attest.php                                          (Task 4.13)
tests/TestCase.php                                         (Task 4.0)
tests/Support/ChainIdHasherTest.php                        (Task 4.1)
tests/Support/TimestampTest.php                            (Task 4.2)
tests/Stores/Locking/SqliteChainLockerTest.php             (Task 4.4)
tests/Stores/Locking/MysqlChainLockerTest.php              (Task 4.5)
tests/Stores/Locking/PostgresChainLockerTest.php           (Task 4.6)
tests/Stores/EloquentChainStoreReadTest.php                (Task 4.9)
tests/Stores/EloquentChainStoreAppendTest.php              (Task 4.10)
tests/Stores/EloquentAnchorClaimStoreTest.php              (Task 4.11)
tests/Contract/ChainStoreContractTests.php                 (Task 4.12)
tests/Contract/AnchorClaimStoreContractTests.php           (Task 4.12)
tests/AttestServiceProviderTest.php                        (Task 4.13)
tests/Concurrency/EloquentConcurrencyTest.php              (Task 4.14)
```

### Modified files

```
composer.json                                              (Task 4.0)
phpstan.neon                                               (Task 4.0)
.github/workflows/ci.yml                                   (Task 4.15)
README.md                                                  (Task 4.16)
CHANGELOG.md                                               (Task 4.16)
VERSION                                                    (Task 4.16)
```

---

## Task 4.0: Scaffold dev environment

**Why:** The repo has Chunk 0 scaffolding (composer.json, phpstan.neon, phpunit.xml, empty src/tests). Add `mockery/mockery` for service-provider tests, wire a local path repository so `fissible/attest` resolves against `~/lib/fissible/attest` during dev, create a stub `AttestServiceProvider` so `composer install` doesn't bust on auto-discovery, and add the Testbench base `TestCase` everything else extends.

**Files:**
- Modify: `composer.json`
- Modify: `phpstan.neon`
- Create: `src/AttestServiceProvider.php` (stub — full in Task 4.13)
- Create: `tests/TestCase.php`

- [ ] **Step 1: Add path repository and mockery to composer.json**

Add a `repositories` block before `extra`:

```json
    "repositories": [
        {
            "type": "path",
            "url": "../attest",
            "options": {"symlink": false}
        }
    ],
```

(Local path with `symlink: false` so we install a fresh copy each time, not a symlinked source.)

Add `mockery/mockery: ^1.6` to `require-dev`:

```json
    "require-dev": {
        "mockery/mockery": "^1.6",
        "orchestra/testbench": "^9.0 || ^10.0",
        "phpstan/phpstan": "^1.10",
        "phpunit/phpunit": "^11.0"
    },
```

- [ ] **Step 2: Expand phpstan path coverage**

Replace `phpstan.neon` contents:

```neon
parameters:
  level: 8
  paths:
    - src
  treatPhpDocTypesAsCertain: false
  ignoreErrors:
    - identifier: missingType.iterableValue
```

(`missingType.iterableValue` is the noisy `iterable<mixed>` warning that PHPStan 1.x raises for every iterable; we already use `@return iterable<…>` docblocks where it matters.)

- [ ] **Step 3: Create the stub `AttestServiceProvider`**

`src/AttestServiceProvider.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel;

use Illuminate\Support\ServiceProvider;

/**
 * Stub. Bindings, config publishing, and migration auto-loading land
 * in Task 4.13. Exists now so composer auto-discovery does not error
 * when the package is installed.
 */
final class AttestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
```

- [ ] **Step 4: Create the Testbench base `TestCase`**

`tests/TestCase.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests;

use Fissible\AttestLaravel\AttestServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return array<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AttestServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new \RuntimeException("Unknown DB_CONNECTION: $driver");
        }
        $app['config']->set('database.default', $driver);
        $app['config']->set("database.connections.$driver", $this->driverConfig($driver));
    }

    /** @return array<string,mixed> */
    private function driverConfig(string $driver): array
    {
        return match ($driver) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'foreign_key_constraints' => true,
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 3306),
                'database' => getenv('DB_DATABASE') ?: 'attest',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 5432),
                'database' => getenv('DB_DATABASE') ?: 'attest',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8',
            ],
        };
    }
}
```

- [ ] **Step 5: Install and verify**

```
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

Expected:
- `composer install` succeeds; `fissible/attest` is installed from the path repo.
- `phpunit` runs zero tests and exits 0 (no tests yet).
- `phpstan` reports no errors (stub provider + base test case are clean).

- [ ] **Step 6: Commit**

```
git add composer.json composer.lock phpstan.neon src/AttestServiceProvider.php tests/TestCase.php
git commit -m "$(cat <<'EOF'
chore: scaffold dev environment for Chunk 4

- Path repository for fissible/attest dev linkage.
- mockery/mockery as a dev dependency for ServiceProvider tests.
- PHPStan ignores iterable<mixed> noise.
- Stub AttestServiceProvider so composer auto-discovery resolves
  (full implementation in Task 4.13).
- Testbench base TestCase wired with DB_CONNECTION-driven environment
  for the SQLite/MySQL/Postgres CI matrix.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4.1: `ChainIdHasher` helper

**Why:** Per-driver lockers all hash chain IDs the same way: `substr(sha256($chainId), 0, 32)`. One helper, one test, every locker uses it.

**Files:**
- Create: `src/Support/ChainIdHasher.php`
- Create: `tests/Support/ChainIdHasherTest.php`

- [ ] **Step 1: Write failing test**

`tests/Support/ChainIdHasherTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Support;

use Fissible\AttestLaravel\Support\ChainIdHasher;
use PHPUnit\Framework\TestCase;

final class ChainIdHasherTest extends TestCase
{
    public function test_hash_returns_32_char_lowercase_hex(): void
    {
        $hash = ChainIdHasher::hash('tenant:5');
        self::assertSame(32, strlen($hash));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $hash);
    }

    public function test_hash_is_deterministic(): void
    {
        self::assertSame(
            ChainIdHasher::hash('tenant:5'),
            ChainIdHasher::hash('tenant:5'),
        );
    }

    public function test_different_inputs_produce_different_hashes(): void
    {
        self::assertNotSame(
            ChainIdHasher::hash('tenant:5'),
            ChainIdHasher::hash('tenant:6'),
        );
    }

    public function test_hash_is_first_32_hex_chars_of_sha256(): void
    {
        $expected = substr(hash('sha256', 'tenant:5'), 0, 32);
        self::assertSame($expected, ChainIdHasher::hash('tenant:5'));
    }
}
```

- [ ] **Step 2: Run, expect fail (class missing)**

```
vendor/bin/phpunit --filter ChainIdHasherTest
```

- [ ] **Step 3: Implement**

`src/Support/ChainIdHasher.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

final class ChainIdHasher
{
    /** Returns the first 32 hex chars of sha256($chainId) — the canonical
     *  locker key for both MySQL named locks and (after derivation) the
     *  two 32-bit keys used by Postgres advisory locks. */
    public static function hash(string $chainId): string
    {
        return substr(hash('sha256', $chainId), 0, 32);
    }
}
```

- [ ] **Step 4: Run, expect pass**

```
vendor/bin/phpunit --filter ChainIdHasherTest
```

- [ ] **Step 5: Commit**

```
git add src/Support/ChainIdHasher.php tests/Support/ChainIdHasherTest.php
git commit -m "feat(support): ChainIdHasher::hash() — 32-char sha256 prefix used by lockers"
```

---

## Task 4.2: `Timestamp` helper

**Why:** Spec §8.2 requires every wall-clock write (envelope `created_at`, claim `claimed_at`, claim `completed_at`, expiry cutoff) to be a `Y-m-d H:i:s.u` UTC string. Laravel's grammar formatters drop microseconds when binding `DateTimeInterface`; we sidestep that by formatting to a string ourselves.

**Files:**
- Create: `src/Support/Timestamp.php`
- Create: `tests/Support/TimestampTest.php`

- [ ] **Step 1: Write failing test**

`tests/Support/TimestampTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Support;

use Fissible\AttestLaravel\Support\Timestamp;
use PHPUnit\Framework\TestCase;

final class TimestampTest extends TestCase
{
    public function test_format_preserves_microseconds(): void
    {
        $t = new \DateTimeImmutable('2026-06-06T14:32:11.123456Z');
        self::assertSame('2026-06-06 14:32:11.123456', Timestamp::format($t));
    }

    public function test_format_normalizes_to_utc(): void
    {
        $t = new \DateTimeImmutable('2026-06-06T14:32:11.123456-04:00');
        // 14:32:11 -04:00 == 18:32:11 UTC
        self::assertSame('2026-06-06 18:32:11.123456', Timestamp::format($t));
    }

    public function test_format_accepts_datetime_interface(): void
    {
        $t = new \DateTime('2026-06-06T14:32:11.123456Z');
        self::assertSame('2026-06-06 14:32:11.123456', Timestamp::format($t));
    }

    public function test_from_envelope_ts_parses_iso8601_with_milliseconds(): void
    {
        // EvidenceEnvelope.ts is formatted as Y-m-d\TH:i:s.v\Z in core.
        $iso = '2026-06-06T14:32:11.123Z';
        // .v is milliseconds; the helper should pad to microseconds.
        self::assertSame('2026-06-06 14:32:11.123000', Timestamp::fromEnvelopeTs($iso));
    }

    public function test_format_matches_canonical_constant(): void
    {
        self::assertSame('Y-m-d H:i:s.u', Timestamp::FORMAT);
    }
}
```

- [ ] **Step 2: Run, expect fail**

```
vendor/bin/phpunit --filter TimestampTest
```

- [ ] **Step 3: Implement**

`src/Support/Timestamp.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

final class Timestamp
{
    /** Canonical wire format used for every attest write. UTC,
     *  microsecond precision, dialect-portable when bound as a string
     *  rather than as a DateTimeInterface (Laravel's grammar drops
     *  fractional seconds). */
    public const FORMAT = 'Y-m-d H:i:s.u';

    public static function format(\DateTimeInterface $when): string
    {
        $utc = $when instanceof \DateTimeImmutable
            ? $when->setTimezone(new \DateTimeZone('UTC'))
            : \DateTimeImmutable::createFromInterface($when)->setTimezone(new \DateTimeZone('UTC'));
        return $utc->format(self::FORMAT);
    }

    public static function fromEnvelopeTs(string $iso8601): string
    {
        return self::format(new \DateTimeImmutable($iso8601));
    }
}
```

- [ ] **Step 4: Run, expect pass**

```
vendor/bin/phpunit --filter TimestampTest
```

- [ ] **Step 5: Commit**

```
git add src/Support/Timestamp.php tests/Support/TimestampTest.php
git commit -m "feat(support): Timestamp helper formats Y-m-d H:i:s.u UTC for all writes"
```

---

## Task 4.3: `ChainLocker` interface

**Why:** Strategy interface for the three driver-specific lockers. No implementation; just the contract. Tests come with the concrete implementations.

**Files:**
- Create: `src/Stores/Locking/ChainLocker.php`

- [ ] **Step 1: Implement**

`src/Stores/Locking/ChainLocker.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;

interface ChainLocker
{
    /**
     * Acquire a per-chain write lock, invoke $work inside a transaction,
     * and release the lock on commit or rollback.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws ChainLockUnavailable when the lock cannot be acquired within timeout
     */
    public function withChainLock(string $chainId, callable $work): mixed;
}
```

- [ ] **Step 2: PHPStan check**

```
vendor/bin/phpstan analyse --no-progress
```

Expected: clean.

- [ ] **Step 3: Commit**

```
git add src/Stores/Locking/ChainLocker.php
git commit -m "feat(locking): ChainLocker interface — strategy contract for per-driver locks"
```

---

## Task 4.4: `SqliteChainLocker`

**Why:** First concrete locker. Raw PDO `BEGIN IMMEDIATE` (Laravel's transaction wrapper starts a *deferred* transaction, wrong semantics here). `PRAGMA busy_timeout` honors `lock_timeout_seconds`. SQLite's write lock is database-wide — documented in code and README.

**Files:**
- Create: `src/Stores/Locking/SqliteChainLocker.php`
- Create: `tests/Stores/Locking/SqliteChainLockerTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Stores/Locking/SqliteChainLockerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SqliteChainLockerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_CONNECTION') !== false && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->markTestSkipped('SQLite locker only runs against sqlite');
        }
        Schema::create('attest_lock_probe', fn ($t) => $t->id());
    }

    public function test_acquires_lock_runs_work_and_commits(): void
    {
        $locker = new SqliteChainLocker(DB::connection(), timeoutSeconds: 10);
        $result = $locker->withChainLock('tenant:5', function () {
            DB::table('attest_lock_probe')->insert([]);
            return 'ok';
        });
        self::assertSame('ok', $result);
        self::assertSame(1, DB::table('attest_lock_probe')->count());
    }

    public function test_rolls_back_when_work_throws(): void
    {
        $locker = new SqliteChainLocker(DB::connection(), timeoutSeconds: 10);
        try {
            $locker->withChainLock('tenant:5', function () {
                DB::table('attest_lock_probe')->insert([]);
                throw new \RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame(0, DB::table('attest_lock_probe')->count());
    }

    public function test_sets_pragma_busy_timeout_in_milliseconds(): void
    {
        // The PRAGMA value is set on the connection right before BEGIN
        // IMMEDIATE. We can read it back to confirm it equals
        // timeoutSeconds * 1000.
        $locker = new SqliteChainLocker(DB::connection(), timeoutSeconds: 7);
        $locker->withChainLock('tenant:5', function (): void {
            $row = DB::selectOne('PRAGMA busy_timeout');
            self::assertSame(7000, (int) $row->timeout);
        });
    }
}
```

- [ ] **Step 2: Run, expect fail (class missing)**

```
vendor/bin/phpunit --filter SqliteChainLockerTest
```

- [ ] **Step 3: Implement**

`src/Stores/Locking/SqliteChainLocker.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Illuminate\Database\ConnectionInterface;

final class SqliteChainLocker implements ChainLocker
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $timeoutSeconds,
    ) {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be >= 0');
        }
    }

    public function withChainLock(string $chainId, callable $work): mixed
    {
        $pdo = $this->connection->getPdo();

        // SQLite's write lock is *database-wide*, not per-chain.
        // PRAGMA busy_timeout makes contending BEGIN IMMEDIATE wait
        // (with internal retries) up to N ms instead of immediately
        // raising SQLITE_BUSY, so the configured lock_timeout_seconds
        // is honored.
        $pdo->exec('PRAGMA busy_timeout = ' . ($this->timeoutSeconds * 1000));

        // Laravel's $connection->beginTransaction() starts a *deferred*
        // transaction and bumps Laravel's transactionLevel counter.
        // We want BEGIN IMMEDIATE up-front and we don't want Laravel's
        // counter touched — issue everything via raw PDO.
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'database is locked')) {
                throw new ChainLockUnavailable($chainId);
            }
            throw $e;
        }

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

- [ ] **Step 4: Run, expect pass**

```
DB_CONNECTION=sqlite vendor/bin/phpunit --filter SqliteChainLockerTest
```

- [ ] **Step 5: PHPStan**

```
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 6: Commit**

```
git add src/Stores/Locking/SqliteChainLocker.php tests/Stores/Locking/SqliteChainLockerTest.php
git commit -m "feat(locking): SqliteChainLocker — raw-PDO BEGIN IMMEDIATE with busy_timeout"
```

---

## Task 4.5: `MysqlChainLocker`

**Why:** MySQL named locks via `GET_LOCK` / `RELEASE_LOCK`. The `$acquired` flag is critical: release only when we hold the lock (return value exactly `1`).

**Files:**
- Create: `src/Stores/Locking/MysqlChainLocker.php`
- Create: `tests/Stores/Locking/MysqlChainLockerTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Stores/Locking/MysqlChainLockerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\AttestLaravel\Stores\Locking\MysqlChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MysqlChainLockerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_CONNECTION') !== 'mysql') {
            $this->markTestSkipped('MySQL locker only runs against mysql');
        }
        Schema::dropIfExists('attest_lock_probe');
        Schema::create('attest_lock_probe', fn ($t) => $t->id());
    }

    public function test_acquires_lock_and_releases_in_finally(): void
    {
        $locker = new MysqlChainLocker(DB::connection(), timeoutSeconds: 5);
        $result = $locker->withChainLock('tenant:5', function () {
            DB::table('attest_lock_probe')->insert([]);
            return 'ok';
        });
        self::assertSame('ok', $result);

        // After return, the lock must be released so a fresh acquisition
        // succeeds immediately.
        $row = DB::selectOne(
            "SELECT IS_FREE_LOCK('attest:chain:" . substr(hash('sha256', 'tenant:5'), 0, 32) . "') AS free"
        );
        self::assertSame(1, (int) $row->free);
    }

    public function test_throws_chainlockunavailable_on_timeout_from_other_session(): void
    {
        $name = 'attest:chain:' . substr(hash('sha256', 'tenant:5'), 0, 32);

        // Acquire on a second connection so the locker's session can't take it.
        $other = DB::connection('mysql');
        $other->reconnect();
        $other->selectOne('SELECT GET_LOCK(?, 5) AS got', [$name]);

        $locker = new MysqlChainLocker(DB::connection(), timeoutSeconds: 1);
        $this->expectException(ChainLockUnavailable::class);
        try {
            $locker->withChainLock('tenant:5', fn () => null);
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public function test_releases_lock_when_work_throws(): void
    {
        $locker = new MysqlChainLocker(DB::connection(), timeoutSeconds: 5);
        try {
            $locker->withChainLock('tenant:5', function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (\RuntimeException) {
        }
        $row = DB::selectOne(
            "SELECT IS_FREE_LOCK('attest:chain:" . substr(hash('sha256', 'tenant:5'), 0, 32) . "') AS free"
        );
        self::assertSame(1, (int) $row->free);
    }
}
```

- [ ] **Step 2: Run, expect fail**

```
DB_CONNECTION=mysql vendor/bin/phpunit --filter MysqlChainLockerTest
```

(Locally, skip if no MySQL container; CI exercises this against `mysql:8.0`.)

- [ ] **Step 3: Implement**

`src/Stores/Locking/MysqlChainLocker.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\AttestLaravel\Support\ChainIdHasher;
use Illuminate\Database\ConnectionInterface;

final class MysqlChainLocker implements ChainLocker
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $timeoutSeconds,
    ) {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be >= 0');
        }
    }

    public function withChainLock(string $chainId, callable $work): mixed
    {
        $lockName = 'attest:chain:' . ChainIdHasher::hash($chainId);
        $acquired = false;

        try {
            // GET_LOCK returns 1 on success, 0 on timeout, NULL on error.
            // Only the literal 1 counts as acquisition.
            $row = $this->connection->selectOne(
                'SELECT GET_LOCK(?, ?) AS got',
                [$lockName, $this->timeoutSeconds],
            );
            $acquired = $row !== null && (int) $row->got === 1;

            if (! $acquired) {
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
}
```

- [ ] **Step 4: Run, expect pass (in CI or local MySQL)**

```
DB_CONNECTION=mysql vendor/bin/phpunit --filter MysqlChainLockerTest
```

- [ ] **Step 5: Commit**

```
git add src/Stores/Locking/MysqlChainLocker.php tests/Stores/Locking/MysqlChainLockerTest.php
git commit -m "feat(locking): MysqlChainLocker — GET_LOCK with \$acquired guard"
```

---

## Task 4.6: `PostgresChainLocker`

**Why:** Postgres advisory locks via `pg_try_advisory_xact_lock`. Three subtleties: (1) the transaction must begin **before** polling; (2) the two keys must be signed 32-bit ints (Postgres `int4` is signed); (3) the PDO driver's boolean return for the lock test varies (`true`, `1`, or `'t'` depending on connection options) so accept all three.

**Files:**
- Create: `src/Stores/Locking/PostgresChainLocker.php`
- Create: `tests/Stores/Locking/PostgresChainLockerTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Stores/Locking/PostgresChainLockerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\AttestLaravel\Stores\Locking\PostgresChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PostgresChainLockerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_CONNECTION') !== 'pgsql') {
            $this->markTestSkipped('Postgres locker only runs against pgsql');
        }
        Schema::dropIfExists('attest_lock_probe');
        Schema::create('attest_lock_probe', fn ($t) => $t->id());
    }

    public function test_acquires_lock_runs_work_and_commits(): void
    {
        $locker = new PostgresChainLocker(DB::connection(), timeoutSeconds: 5, pollUs: 50_000);
        $result = $locker->withChainLock('tenant:5', function () {
            DB::table('attest_lock_probe')->insert([]);
            return 'ok';
        });
        self::assertSame('ok', $result);
        self::assertSame(1, DB::table('attest_lock_probe')->count());
    }

    public function test_rolls_back_when_work_throws(): void
    {
        $locker = new PostgresChainLocker(DB::connection(), timeoutSeconds: 5, pollUs: 50_000);
        try {
            $locker->withChainLock('tenant:5', function (): void {
                DB::table('attest_lock_probe')->insert([]);
                throw new \RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, DB::table('attest_lock_probe')->count());
    }

    public function test_times_out_when_other_session_holds_lock(): void
    {
        $hash = hash('sha256', 'tenant:5', binary: true);
        $unsigned = array_values(unpack('N2', substr($hash, 0, 8)));
        $k1 = $unsigned[0] >= 0x80000000 ? $unsigned[0] - 0x100000000 : $unsigned[0];
        $k2 = $unsigned[1] >= 0x80000000 ? $unsigned[1] - 0x100000000 : $unsigned[1];

        $other = DB::connection('pgsql');
        $other->reconnect();
        $other->beginTransaction();
        $other->selectOne('SELECT pg_advisory_xact_lock(?, ?)', [$k1, $k2]);

        $locker = new PostgresChainLocker(DB::connection(), timeoutSeconds: 1, pollUs: 50_000);
        try {
            $this->expectException(ChainLockUnavailable::class);
            $locker->withChainLock('tenant:5', fn () => null);
        } finally {
            $other->rollBack();
        }
    }

    public function test_signed_int_conversion_for_high_bit_keys(): void
    {
        // The locker uses pg_try_advisory_xact_lock(int, int). Postgres
        // int4 is signed; pass an unsigned value where the high bit is
        // set and the locker must wrap it. This test exercises the
        // toSignedInt32 helper indirectly by using a chain_id whose
        // sha256 prefix has a high-bit-set first word.
        // (Any reasonably-collisive chain_id reaches both code paths.)
        $locker = new PostgresChainLocker(DB::connection(), timeoutSeconds: 1, pollUs: 50_000);
        $result = $locker->withChainLock(
            'high-bit-bait-' . str_repeat('z', 32),
            fn () => 'ok',
        );
        self::assertSame('ok', $result);
    }
}
```

- [ ] **Step 2: Run, expect fail**

```
DB_CONNECTION=pgsql vendor/bin/phpunit --filter PostgresChainLockerTest
```

- [ ] **Step 3: Implement**

`src/Stores/Locking/PostgresChainLocker.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Illuminate\Database\ConnectionInterface;

final class PostgresChainLocker implements ChainLocker
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $timeoutSeconds,
        private readonly int $pollUs = 50_000,  // 50ms default
    ) {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be >= 0');
        }
        if ($pollUs < 1) {
            throw new \InvalidArgumentException('pollUs must be >= 1');
        }
    }

    public function withChainLock(string $chainId, callable $work): mixed
    {
        $hash = hash('sha256', $chainId, binary: true);
        // Two unsigned 32-bit values from the first 8 bytes …
        $unsigned = unpack('N2', substr($hash, 0, 8));
        // … converted to signed int32 because Postgres int4 is signed.
        $k1 = $this->toSignedInt32($unsigned[1]);
        $k2 = $this->toSignedInt32($unsigned[2]);

        // The transaction must begin *before* polling. pg_try_advisory_xact_lock
        // outside a transaction would acquire and immediately release.
        $this->connection->beginTransaction();
        try {
            $deadline = microtime(true) + $this->timeoutSeconds;
            while (true) {
                $row = $this->connection->selectOne(
                    'SELECT pg_try_advisory_xact_lock(?, ?) AS got',
                    [$k1, $k2],
                );
                if ($row !== null && $this->isTruthy($row->got)) {
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

    /** Accept the PDO boolean-ish forms: true, 1, 't', '1'. The exact
     *  return type depends on the connection's ATTR_STRINGIFY_FETCHES
     *  and ATTR_EMULATE_PREPARES settings. */
    private function isTruthy(mixed $value): bool
    {
        if ($value === true) return true;
        if ($value === 1) return true;
        if (is_string($value)) {
            $v = strtolower($value);
            return $v === 't' || $v === '1' || $v === 'true';
        }
        return false;
    }

    private function toSignedInt32(int $u): int
    {
        return $u >= 0x80000000 ? $u - 0x100000000 : $u;
    }
}
```

- [ ] **Step 4: Run, expect pass**

```
DB_CONNECTION=pgsql vendor/bin/phpunit --filter PostgresChainLockerTest
```

- [ ] **Step 5: Commit**

```
git add src/Stores/Locking/PostgresChainLocker.php tests/Stores/Locking/PostgresChainLockerTest.php
git commit -m "feat(locking): PostgresChainLocker — try_advisory_xact_lock polling with signed-int32 keys"
```

---

## Task 4.7: Database migrations

**Why:** Two tables — `attest_envelopes` and `attest_anchor_claims` — written via Laravel's Schema builder so DDL is dialect-correct. `raw_envelope` is `mediumText`, never `json`.

**Files:**
- Create: `database/migrations/2026_06_06_000001_create_attest_envelopes_table.php`
- Create: `database/migrations/2026_06_06_000002_create_attest_anchor_claims_table.php`

- [ ] **Step 1: Create `attest_envelopes` migration**

`database/migrations/2026_06_06_000001_create_attest_envelopes_table.php`:

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attest_envelopes', function (Blueprint $t): void {
            $t->id();
            $t->string('chain_id', 191);
            $t->unsignedBigInteger('sequence');
            $t->char('envelope_id', 26);
            $t->string('prev_hash', 80)->nullable();
            $t->string('self_hash', 80);
            $t->string('key_id', 191);
            $t->string('type', 191);
            $t->mediumText('raw_envelope');
            // TIMESTAMP(6) on MySQL/SQLite, TIMESTAMPTZ(6) on Postgres.
            $t->timestampTz('created_at', precision: 6);
            $t->unique(['chain_id', 'sequence']);
            $t->unique('envelope_id');
            $t->index(['chain_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attest_envelopes');
    }
};
```

- [ ] **Step 2: Create `attest_anchor_claims` migration**

`database/migrations/2026_06_06_000002_create_attest_anchor_claims_table.php`:

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attest_anchor_claims', function (Blueprint $t): void {
            $t->char('anchor_id', 64)->primary();
            $t->string('chain_id', 191);
            $t->unsignedBigInteger('from_seq');
            $t->unsignedBigInteger('to_seq');
            $t->string('driver', 64);
            $t->string('claimed_by', 255);
            $t->timestampTz('claimed_at', precision: 6);
            $t->timestampTz('completed_at', precision: 6)->nullable();
            $t->char('completed_envelope_id', 26)->nullable();
            $t->index(['chain_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attest_anchor_claims');
    }
};
```

- [ ] **Step 3: Wire migrations into stub ServiceProvider so they auto-load**

Modify `src/AttestServiceProvider.php` `boot()`:

```php
public function boot(): void
{
    $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
}
```

- [ ] **Step 4: Run a smoke test under Testbench**

Create `tests/MigrationsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

final class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_attest_envelopes_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('attest_envelopes'));
        self::assertTrue(Schema::hasColumns('attest_envelopes', [
            'id', 'chain_id', 'sequence', 'envelope_id', 'prev_hash',
            'self_hash', 'key_id', 'type', 'raw_envelope', 'created_at',
        ]));
    }

    public function test_attest_anchor_claims_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('attest_anchor_claims'));
        self::assertTrue(Schema::hasColumns('attest_anchor_claims', [
            'anchor_id', 'chain_id', 'from_seq', 'to_seq', 'driver',
            'claimed_by', 'claimed_at', 'completed_at', 'completed_envelope_id',
        ]));
    }
}
```

Run:

```
vendor/bin/phpunit --filter MigrationsTest
```

Expected: pass against SQLite (default). CI will also exercise MySQL and Postgres.

- [ ] **Step 5: Commit**

```
git add database/migrations/ src/AttestServiceProvider.php tests/MigrationsTest.php
git commit -m "feat(db): migrations for attest_envelopes and attest_anchor_claims tables"
```

---

## Task 4.8: Eloquent models

**Why:** Minimal Eloquent wrappers. `$timestamps = false` on both because the store writes `created_at`/`claimed_at` explicitly from the envelope and there is no `updated_at`.

**Files:**
- Create: `src/Models/AttestEnvelope.php`
- Create: `src/Models/AttestAnchorClaim.php`
- Create: `tests/Models/AttestEnvelopeTest.php` (single round-trip test exercising the casts)

- [ ] **Step 1: Write a model round-trip test**

`tests/Models/AttestEnvelopeTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Models;

use Fissible\AttestLaravel\Models\AttestEnvelope;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class AttestEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_does_not_set_timestamps(): void
    {
        $m = new AttestEnvelope();
        self::assertFalse($m->timestamps);
    }

    public function test_model_round_trip_via_query_builder(): void
    {
        DB::table('attest_envelopes')->insert([
            'chain_id' => 'c',
            'sequence' => 1,
            'envelope_id' => '01H00000000000000000000000',
            'prev_hash' => null,
            'self_hash' => str_repeat('a', 64),
            'key_id' => 'k1',
            'type' => 't',
            'raw_envelope' => '{"v":1}',
            'created_at' => '2026-06-06 14:32:11.123456',
        ]);

        $m = AttestEnvelope::query()->where('chain_id', 'c')->first();
        self::assertNotNull($m);
        self::assertSame(1, $m->sequence);
        self::assertSame('{"v":1}', $m->raw_envelope);
    }
}
```

- [ ] **Step 2: Run, expect fail**

```
vendor/bin/phpunit --filter AttestEnvelopeTest
```

- [ ] **Step 3: Implement `AttestEnvelope`**

`src/Models/AttestEnvelope.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-side query convenience over attest_envelopes. The store owns
 * all writes; do NOT call ->save() to add envelopes.
 *
 * @property int $id
 * @property string $chain_id
 * @property int $sequence
 * @property string $envelope_id
 * @property ?string $prev_hash
 * @property string $self_hash
 * @property string $key_id
 * @property string $type
 * @property string $raw_envelope
 * @property \Illuminate\Support\Carbon $created_at
 */
final class AttestEnvelope extends Model
{
    protected $table = 'attest_envelopes';

    /** Store writes created_at explicitly from the envelope ts; no
     *  updated_at exists. */
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'created_at' => 'datetime',
    ];
}
```

- [ ] **Step 4: Implement `AttestAnchorClaim`**

`src/Models/AttestAnchorClaim.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $anchor_id
 * @property string $chain_id
 * @property int $from_seq
 * @property int $to_seq
 * @property string $driver
 * @property string $claimed_by
 * @property \Illuminate\Support\Carbon $claimed_at
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?string $completed_envelope_id
 */
final class AttestAnchorClaim extends Model
{
    protected $table = 'attest_anchor_claims';
    public $timestamps = false;
    protected $primaryKey = 'anchor_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'from_seq' => 'integer',
        'to_seq' => 'integer',
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
```

- [ ] **Step 5: Run, expect pass**

```
vendor/bin/phpunit --filter AttestEnvelopeTest
```

- [ ] **Step 6: Commit**

```
git add src/Models/ tests/Models/
git commit -m "feat(models): AttestEnvelope and AttestAnchorClaim with timestamps=false"
```

---

## Task 4.9: `EloquentChainStore` — read paths

**Why:** Implement read side first: `tail`, `readRange`, `readRawRange`, `listChains`, `exists`. All decode only `raw_envelope`; indexed columns are read-side metadata. Append comes in Task 4.10.

**Files:**
- Create: `src/Stores/EloquentChainStore.php` (read-only skeleton; append in 4.10)
- Create: `tests/Stores/EloquentChainStoreReadTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Stores/EloquentChainStoreReadTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores;

use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class EloquentChainStoreReadTest extends TestCase
{
    use RefreshDatabase;

    private function insertCanonical(string $chainId, int $seq, string $raw): void
    {
        DB::table('attest_envelopes')->insert([
            'chain_id' => $chainId,
            'sequence' => $seq,
            'envelope_id' => sprintf('01H%023d', $seq),
            'prev_hash' => null,
            'self_hash' => str_repeat('a', 64),
            'key_id' => 'k1',
            'type' => 't',
            'raw_envelope' => $raw,
            'created_at' => '2026-06-06 14:32:11.000000',
        ]);
    }

    public function test_exists_is_true_for_known_chain(): void
    {
        $this->insertCanonical('c', 1, $this->validEnvelope(1));
        $store = new EloquentChainStore(DB::connection(), $this->dummyLocker());
        self::assertTrue($store->exists('c'));
        self::assertFalse($store->exists('other'));
    }

    public function test_list_chains_yields_distinct_chain_ids(): void
    {
        $this->insertCanonical('c1', 1, $this->validEnvelope(1, 'c1'));
        $this->insertCanonical('c1', 2, $this->validEnvelope(2, 'c1'));
        $this->insertCanonical('c2', 1, $this->validEnvelope(1, 'c2'));
        $store = new EloquentChainStore(DB::connection(), $this->dummyLocker());
        self::assertEqualsCanonicalizing(['c1', 'c2'], iterator_to_array($store->listChains(), false));
    }

    public function test_tail_returns_highest_sequence_envelope(): void
    {
        $this->insertCanonical('c', 1, $this->validEnvelope(1));
        $this->insertCanonical('c', 2, $this->validEnvelope(2));
        $store = new EloquentChainStore(DB::connection(), $this->dummyLocker());
        $tail = $store->tail('c');
        self::assertNotNull($tail);
        self::assertSame(2, $tail->envelope->seq);
    }

    public function test_readRange_yields_envelopes_in_seq_order(): void
    {
        $this->insertCanonical('c', 1, $this->validEnvelope(1));
        $this->insertCanonical('c', 2, $this->validEnvelope(2));
        $this->insertCanonical('c', 3, $this->validEnvelope(3));
        $store = new EloquentChainStore(DB::connection(), $this->dummyLocker());
        $envs = iterator_to_array($store->readRange('c', 2, 3), false);
        self::assertCount(2, $envs);
        self::assertSame(2, $envs[0]->envelope->seq);
        self::assertSame(3, $envs[1]->envelope->seq);
    }

    public function test_readRawRange_yields_byte_identical_strings(): void
    {
        $raw = $this->validEnvelope(1);
        $this->insertCanonical('c', 1, $raw);
        $store = new EloquentChainStore(DB::connection(), $this->dummyLocker());
        $rows = iterator_to_array($store->readRawRange('c', 1), false);
        self::assertSame([$raw], $rows);
    }

    /** A valid signed-envelope JSON line that decodes via EnvelopeCodec.
     *  We don't sign for read-side tests; instead we use a fixture-style
     *  envelope generated by signing once and stored verbatim. */
    private function validEnvelope(int $seq, string $chain = 'c'): string
    {
        // Build via core's SignedEnvelope at test time so it round-trips.
        $kp = \Fissible\Attest\Signing\KeyPair::generate();
        $signer = new \Fissible\Attest\Signing\SodiumSigner($kp, keyId: 'k1');
        $env = new \Fissible\Attest\Envelope\EvidenceEnvelope(
            id: sprintf('01H%023d', $seq),
            chain: $chain,
            seq: $seq,
            ts: '2026-06-06T14:32:11.000Z',
            type: 'app.event',
            payload: ['n' => $seq],
            prevHash: null,
            keyId: 'k1',
            sigAlg: 'ed25519',
        );
        $signed = \Fissible\Attest\Envelope\SignedEnvelope::sign($env, $signer);
        return $signed->signedCanonicalBytes();
    }

    private function dummyLocker(): \Fissible\AttestLaravel\Stores\Locking\ChainLocker
    {
        // Read paths never call the locker — return a stub that throws if hit.
        return new class implements \Fissible\AttestLaravel\Stores\Locking\ChainLocker {
            public function withChainLock(string $chainId, callable $work): mixed
            {
                throw new \LogicException('locker should not be touched on read paths');
            }
        };
    }
}
```

- [ ] **Step 2: Run, expect fail (class missing)**

```
vendor/bin/phpunit --filter EloquentChainStoreReadTest
```

- [ ] **Step 3: Implement read-only skeleton**

`src/Stores/EloquentChainStore.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores;

use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\RawChainStore;
use Fissible\Attest\Envelope\EnvelopeCodec;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\AttestLaravel\Stores\Locking\ChainLocker;
use Illuminate\Database\ConnectionInterface;

final class EloquentChainStore implements ChainStore, RawChainStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ChainLocker $locker,
    ) {
    }

    public function append(string $chainId, callable $buildAndSign): SignedEnvelope
    {
        throw new \LogicException('append() lands in Task 4.10');
    }

    public function tail(string $chainId): ?SignedEnvelope
    {
        $row = $this->connection->table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->orderByDesc('sequence')
            ->limit(1)
            ->value('raw_envelope');
        return $row === null ? null : EnvelopeCodec::decodeSigned((string) $row);
    }

    public function readRange(string $chainId, int $fromSeq, ?int $toSeq = null): iterable
    {
        if ($fromSeq < 1) {
            throw new \InvalidArgumentException('fromSeq must be >= 1');
        }
        if ($toSeq !== null && $toSeq < $fromSeq) {
            throw new \InvalidArgumentException('toSeq must be >= fromSeq');
        }
        $query = $this->connection->table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', '>=', $fromSeq)
            ->when($toSeq !== null, fn ($q) => $q->where('sequence', '<=', $toSeq))
            ->orderBy('sequence');
        foreach ($query->cursor() as $row) {
            yield EnvelopeCodec::decodeSigned((string) $row->raw_envelope);
        }
    }

    public function readRawRange(string $chainId, int $fromSeq, ?int $toSeq = null): iterable
    {
        if ($fromSeq < 1) {
            throw new \InvalidArgumentException('fromSeq must be >= 1');
        }
        if ($toSeq !== null && $toSeq < $fromSeq) {
            throw new \InvalidArgumentException('toSeq must be >= fromSeq');
        }
        $query = $this->connection->table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', '>=', $fromSeq)
            ->when($toSeq !== null, fn ($q) => $q->where('sequence', '<=', $toSeq))
            ->orderBy('sequence');
        foreach ($query->cursor() as $row) {
            yield (string) $row->raw_envelope;
        }
    }

    public function listChains(): iterable
    {
        foreach ($this->connection->table('attest_envelopes')->distinct()->pluck('chain_id') as $id) {
            yield (string) $id;
        }
    }

    public function exists(string $chainId): bool
    {
        return $this->connection->table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->exists();
    }
}
```

- [ ] **Step 4: Run, expect pass**

```
vendor/bin/phpunit --filter EloquentChainStoreReadTest
```

- [ ] **Step 5: PHPStan**

```
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 6: Commit**

```
git add src/Stores/EloquentChainStore.php tests/Stores/EloquentChainStoreReadTest.php
git commit -m "feat(stores): EloquentChainStore read paths — tail, readRange, readRawRange"
```

---

## Task 4.10: `EloquentChainStore::append` + `EnvelopeRecorded`

**Why:** Wire the write path. Locker-wrapped transaction, `created_at` from envelope `ts` via `Timestamp::fromEnvelopeTs`, events accumulate in a closure-local array and dispatch after `withChainLock` returns.

**Files:**
- Modify: `src/Stores/EloquentChainStore.php`
- Create: `src/Events/EnvelopeRecorded.php`
- Create: `tests/Stores/EloquentChainStoreAppendTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Stores/EloquentChainStoreAppendTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores;

use Fissible\Attest\Chain\AppendContext;
use Fissible\Attest\Chain\ContextMismatch;
use Fissible\Attest\Envelope\EvidenceEnvelope;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Events\EnvelopeRecorded;
use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class EloquentChainStoreAppendTest extends TestCase
{
    use RefreshDatabase;

    public function test_append_persists_envelope_and_fires_event_after_commit(): void
    {
        if (getenv('DB_CONNECTION') !== false && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->markTestSkipped('append test runs against sqlite; per-driver tests run in CI matrix');
        }
        Event::fake([EnvelopeRecorded::class]);

        $store = new EloquentChainStore(DB::connection(), new SqliteChainLocker(DB::connection(), 5));
        $kp = KeyPair::generate();
        $signer = new SodiumSigner($kp, keyId: 'k1');

        $signed = $store->append('tenant:5', function (AppendContext $ctx) use ($signer) {
            $env = new EvidenceEnvelope(
                id: '01H00000000000000000000001',
                chain: $ctx->chainId,
                seq: $ctx->sequence,
                ts: $ctx->timestampIso8601,
                type: 'app.event',
                payload: ['k' => 'v'],
                prevHash: $ctx->prevHash,
                keyId: 'k1',
                sigAlg: 'ed25519',
            );
            return SignedEnvelope::sign($env, $signer);
        });

        self::assertSame(1, $signed->envelope->seq);
        self::assertSame(1, DB::table('attest_envelopes')->count());
        Event::assertDispatched(
            EnvelopeRecorded::class,
            fn (EnvelopeRecorded $e) => $e->chainId === 'tenant:5' && $e->signed->envelope->id === $signed->envelope->id,
        );
    }

    public function test_context_mismatch_throws_and_rolls_back(): void
    {
        Event::fake([EnvelopeRecorded::class]);
        $store = new EloquentChainStore(DB::connection(), new SqliteChainLocker(DB::connection(), 5));
        $kp = KeyPair::generate();
        $signer = new SodiumSigner($kp, keyId: 'k1');

        $this->expectException(ContextMismatch::class);
        try {
            $store->append('tenant:5', function (AppendContext $ctx) use ($signer) {
                // Return an envelope with a *different* chain id than the context.
                $env = new EvidenceEnvelope(
                    id: '01H00000000000000000000002',
                    chain: 'WRONG',
                    seq: $ctx->sequence,
                    ts: $ctx->timestampIso8601,
                    type: 'app.event',
                    payload: [],
                    prevHash: $ctx->prevHash,
                    keyId: 'k1',
                    sigAlg: 'ed25519',
                );
                return SignedEnvelope::sign($env, $signer);
            });
        } finally {
            self::assertSame(0, DB::table('attest_envelopes')->count());
            Event::assertNotDispatched(EnvelopeRecorded::class);
        }
    }

    public function test_created_at_round_trips_with_microsecond_precision(): void
    {
        if (getenv('DB_CONNECTION') !== false && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->markTestSkipped('microsecond round-trip test runs per-driver in CI');
        }
        $store = new EloquentChainStore(DB::connection(), new SqliteChainLocker(DB::connection(), 5));
        $kp = KeyPair::generate();
        $signer = new SodiumSigner($kp, keyId: 'k1');

        $signed = $store->append('tenant:5', function (AppendContext $ctx) use ($signer) {
            $env = new EvidenceEnvelope(
                id: '01H00000000000000000000003',
                chain: $ctx->chainId,
                seq: $ctx->sequence,
                ts: $ctx->timestampIso8601,
                type: 'app.event',
                payload: [],
                prevHash: $ctx->prevHash,
                keyId: 'k1',
                sigAlg: 'ed25519',
            );
            return SignedEnvelope::sign($env, $signer);
        });

        $row = DB::table('attest_envelopes')->first();
        // Confirm the stored created_at contains a microsecond component
        // (i.e., not truncated to seconds).
        self::assertMatchesRegularExpression('/\.\d{6}\z/', (string) $row->created_at);
    }
}
```

- [ ] **Step 2: Run, expect fail**

```
vendor/bin/phpunit --filter EloquentChainStoreAppendTest
```

- [ ] **Step 3: Implement `EnvelopeRecorded`**

`src/Events/EnvelopeRecorded.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Events;

use Fissible\Attest\Envelope\SignedEnvelope;

final readonly class EnvelopeRecorded
{
    public function __construct(
        public string $chainId,
        public SignedEnvelope $signed,
    ) {
    }
}
```

- [ ] **Step 4: Extend `EloquentChainStore` with `append()` and helpers**

Replace the `append()` stub in `src/Stores/EloquentChainStore.php` and add the private helpers. Add `use` statements at the top of the file:

```php
use Fissible\Attest\Chain\AppendContext;
use Fissible\Attest\Chain\ContextMismatch;
use Fissible\AttestLaravel\Events\EnvelopeRecorded;
use Fissible\AttestLaravel\Support\Timestamp;
use Illuminate\Contracts\Events\Dispatcher;
```

Update the constructor to accept a `Dispatcher`:

```php
public function __construct(
    private readonly ConnectionInterface $connection,
    private readonly ChainLocker $locker,
    private readonly Dispatcher $events,
) {
}
```

Replace the `append()` body with:

```php
public function append(string $chainId, callable $buildAndSign): SignedEnvelope
{
    // Closure-local pending list (captured by reference). Singleton
    // store with instance state would leak across failed calls; the
    // local array is throw-safe and dispatch only happens after
    // withChainLock() returns successfully.
    $pending = [];

    $signed = $this->locker->withChainLock(
        $chainId,
        function () use ($chainId, $buildAndSign, &$pending) {
            $tail = $this->tail($chainId);
            $context = new AppendContext(
                chainId: $chainId,
                sequence: $tail === null ? 1 : $tail->envelope->seq + 1,
                prevHash: $tail?->selfHash(),
                timestampIso8601: $this->monotonicTimestamp($tail),
            );

            $signed = $buildAndSign($context);
            $this->validateContext($context, $signed);

            $this->connection->table('attest_envelopes')->insert([
                'chain_id' => $chainId,
                'sequence' => $context->sequence,
                'envelope_id' => $signed->envelope->id,
                'prev_hash' => $signed->envelope->prevHash,
                'self_hash' => $signed->selfHash(),
                'key_id' => $signed->envelope->keyId,
                'type' => $signed->envelope->type,
                'raw_envelope' => $signed->signedCanonicalBytes(),
                'created_at' => Timestamp::fromEnvelopeTs($signed->envelope->ts),
            ]);

            $pending[] = new EnvelopeRecorded($chainId, $signed);
            return $signed;
        },
    );

    foreach ($pending as $event) {
        $this->events->dispatch($event);
    }
    return $signed;
}

private function monotonicTimestamp(?SignedEnvelope $tail): string
{
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    if ($tail === null) {
        return $now;
    }
    if (strcmp($now, $tail->envelope->ts) > 0) {
        return $now;
    }
    return (new \DateTimeImmutable($tail->envelope->ts))
        ->modify('+1 millisecond')
        ->format('Y-m-d\TH:i:s.v\Z');
}

private function validateContext(AppendContext $context, SignedEnvelope $signed): void
{
    if ($signed->envelope->chain !== $context->chainId
        || $signed->envelope->seq !== $context->sequence
        || $signed->envelope->prevHash !== $context->prevHash
        || $signed->envelope->ts !== $context->timestampIso8601
    ) {
        throw new ContextMismatch(sprintf(
            'Envelope context mismatch (expected chain=%s seq=%d prev=%s ts=%s; got chain=%s seq=%d prev=%s ts=%s)',
            $context->chainId, $context->sequence, $context->prevHash ?? 'null', $context->timestampIso8601,
            $signed->envelope->chain, $signed->envelope->seq, $signed->envelope->prevHash ?? 'null', $signed->envelope->ts,
        ));
    }
}
```

Update `EloquentChainStoreReadTest::dummyLocker()` callers — the constructor now takes a third arg. Pass `app('events')` or an `Illuminate\Events\Dispatcher` instance:

```php
private function dummyEvents(): \Illuminate\Contracts\Events\Dispatcher
{
    return new \Illuminate\Events\Dispatcher();
}
// And update each `new EloquentChainStore(...)` call to pass $this->dummyEvents()
```

- [ ] **Step 5: Run all store tests, expect pass**

```
vendor/bin/phpunit --filter EloquentChainStore
```

- [ ] **Step 6: PHPStan**

```
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 7: Commit**

```
git add src/Stores/EloquentChainStore.php src/Events/EnvelopeRecorded.php tests/Stores/EloquentChainStoreAppendTest.php tests/Stores/EloquentChainStoreReadTest.php
git commit -m "feat(stores): EloquentChainStore::append + EnvelopeRecorded post-commit dispatch"
```

---

## Task 4.11: `EloquentAnchorClaimStore`

**Why:** Implements `AnchorClaimStore`. `claim` via `insertOrIgnore`, `release` via `DELETE`, `complete` atomic conditional UPDATE with re-read for conflict detection, `reclaimExpired` with PHP-computed cutoff.

**Files:**
- Create: `src/Stores/EloquentAnchorClaimStore.php`
- Create: `tests/Stores/EloquentAnchorClaimStoreTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Stores/EloquentAnchorClaimStoreTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores;

use Fissible\Attest\Anchor\AnchorClaim;
use Fissible\AttestLaravel\Stores\EloquentAnchorClaimStore;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class EloquentAnchorClaimStoreTest extends TestCase
{
    use RefreshDatabase;

    private function makeClaim(string $by = 'host:1:abc'): AnchorClaim
    {
        return new AnchorClaim(
            chainId: 'tenant:5',
            fromSeq: 1,
            toSeq: 100,
            driver: 'opentimestamps',
            claimedBy: $by,
            claimedAtIso8601: '2026-06-06T14:32:11.000Z',
        );
    }

    public function test_claim_returns_true_first_time_false_second_time(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());
        self::assertTrue($store->claim('aid-1', $this->makeClaim('worker-A')));
        self::assertFalse($store->claim('aid-1', $this->makeClaim('worker-B')));
        self::assertSame(1, DB::table('attest_anchor_claims')->count());
        // The first claimer wins.
        self::assertSame('worker-A', DB::table('attest_anchor_claims')->value('claimed_by'));
    }

    public function test_release_removes_only_incomplete_claims(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());
        $store->claim('aid-1', $this->makeClaim());
        $store->release('aid-1');
        self::assertSame(0, DB::table('attest_anchor_claims')->count());

        $store->claim('aid-2', $this->makeClaim());
        $store->complete('aid-2', '01HX00000000000000000000');
        $store->release('aid-2');
        // Completed rows are NOT released.
        self::assertSame(1, DB::table('attest_anchor_claims')->where('anchor_id', 'aid-2')->count());
    }

    public function test_complete_is_atomic_and_idempotent(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());
        $store->claim('aid-1', $this->makeClaim());
        $store->complete('aid-1', '01HX00000000000000000000');
        // Idempotent retry with the SAME envelope_id.
        $store->complete('aid-1', '01HX00000000000000000000');
        self::assertSame(1, DB::table('attest_anchor_claims')->count());
    }

    public function test_complete_with_different_envelope_id_throws(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());
        $store->claim('aid-1', $this->makeClaim());
        $store->complete('aid-1', '01HX00000000000000000000');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already completed/i');
        $store->complete('aid-1', '01HY00000000000000000000');
    }

    public function test_reclaim_expired_yields_only_expired_incomplete_claims(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());

        // Fresh claim — must NOT be yielded.
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $store->claim('aid-fresh', new AnchorClaim(
            chainId: 'c', fromSeq: 1, toSeq: 1, driver: 'd',
            claimedBy: 'fresh', claimedAtIso8601: $now,
        ));

        // Old claim — MUST be yielded.
        $old = (new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $store->claim('aid-old', new AnchorClaim(
            chainId: 'c', fromSeq: 2, toSeq: 2, driver: 'd',
            claimedBy: 'old', claimedAtIso8601: $old,
        ));

        // Completed-and-old — must NOT be yielded (completed_at IS NOT NULL).
        $store->claim('aid-done', new AnchorClaim(
            chainId: 'c', fromSeq: 3, toSeq: 3, driver: 'd',
            claimedBy: 'done', claimedAtIso8601: $old,
        ));
        $store->complete('aid-done', '01HX00000000000000000000');

        $expired = [];
        foreach ($store->reclaimExpired(ttlSeconds: 3600) as $anchorId => $claim) {
            $expired[$anchorId] = $claim;
        }
        self::assertArrayHasKey('aid-old', $expired);
        self::assertCount(1, $expired);
    }
}
```

- [ ] **Step 2: Run, expect fail**

```
vendor/bin/phpunit --filter EloquentAnchorClaimStoreTest
```

- [ ] **Step 3: Implement**

`src/Stores/EloquentAnchorClaimStore.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores;

use Fissible\Attest\Anchor\AnchorClaim;
use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\AttestLaravel\Support\Timestamp;
use Illuminate\Database\ConnectionInterface;

final class EloquentAnchorClaimStore implements AnchorClaimStore
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function claim(string $anchorId, AnchorClaim $details): bool
    {
        $count = $this->connection->table('attest_anchor_claims')->insertOrIgnore([
            'anchor_id' => $anchorId,
            'chain_id' => $details->chainId,
            'from_seq' => $details->fromSeq,
            'to_seq' => $details->toSeq,
            'driver' => $details->driver,
            'claimed_by' => $details->claimedBy,
            'claimed_at' => Timestamp::fromEnvelopeTs($details->claimedAtIso8601),
        ]);
        return $count > 0;
    }

    public function release(string $anchorId): void
    {
        $this->connection->table('attest_anchor_claims')
            ->where('anchor_id', $anchorId)
            ->whereNull('completed_at')
            ->delete();
    }

    public function complete(string $anchorId, string $envelopeId): void
    {
        // Atomic conditional UPDATE — the WHERE completed_at IS NULL is
        // the compare-and-set. On zero affected rows, re-read to
        // distinguish idempotent retry from real conflict.
        $now = Timestamp::format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $affected = $this->connection->table('attest_anchor_claims')
            ->where('anchor_id', $anchorId)
            ->whereNull('completed_at')
            ->update([
                'completed_at' => $now,
                'completed_envelope_id' => $envelopeId,
            ]);
        if ($affected > 0) {
            return;
        }

        $existing = $this->connection->table('attest_anchor_claims')
            ->where('anchor_id', $anchorId)
            ->first();
        if ($existing === null) {
            throw new \RuntimeException("AnchorClaim $anchorId not found");
        }
        if ($existing->completed_envelope_id === $envelopeId) {
            return;
        }
        throw new \RuntimeException(sprintf(
            'AnchorClaim %s already completed with envelope_id %s; refusing to overwrite with %s',
            $anchorId,
            $existing->completed_envelope_id,
            $envelopeId,
        ));
    }

    public function reclaimExpired(int $ttlSeconds): iterable
    {
        if ($ttlSeconds < 0) {
            throw new \InvalidArgumentException('ttlSeconds must be >= 0');
        }
        $cutoff = Timestamp::format(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->sub(new \DateInterval('PT' . $ttlSeconds . 'S')),
        );
        $rows = $this->connection->table('attest_anchor_claims')
            ->whereNull('completed_at')
            ->where('claimed_at', '<', $cutoff)
            ->cursor();
        foreach ($rows as $row) {
            yield (string) $row->anchor_id => new AnchorClaim(
                chainId: (string) $row->chain_id,
                fromSeq: (int) $row->from_seq,
                toSeq: (int) $row->to_seq,
                driver: (string) $row->driver,
                claimedBy: (string) $row->claimed_by,
                claimedAtIso8601: (string) $row->claimed_at,
            );
        }
    }
}
```

- [ ] **Step 4: Run, expect pass**

```
vendor/bin/phpunit --filter EloquentAnchorClaimStoreTest
```

- [ ] **Step 5: Commit**

```
git add src/Stores/EloquentAnchorClaimStore.php tests/Stores/EloquentAnchorClaimStoreTest.php
git commit -m "feat(stores): EloquentAnchorClaimStore with atomic complete() and PHP-side cutoffs"
```

---

## Task 4.12: Port core contract tests

**Why:** Spec §13.2 requires the same `ChainStore` and `AnchorClaimStore` contract semantics that core enforces. Composer doesn't autoload upstream `tests/`, so we port the traits with attribution.

**Files:**
- Create: `tests/Contract/ChainStoreContractTests.php`
- Create: `tests/Contract/AnchorClaimStoreContractTests.php`
- Modify: `tests/Stores/EloquentChainStoreReadTest.php` (use the trait)
- Modify: `tests/Stores/EloquentAnchorClaimStoreTest.php` (use the trait)

- [ ] **Step 1: Read core's contract test files**

```
cat ~/lib/fissible/attest/tests/Chain/ChainStoreContractTests.php
cat ~/lib/fissible/attest/tests/Anchor/AnchorClaimStoreContractTests.php
```

Note their structure. They are PHPUnit traits that the concrete-store test class uses (`use ChainStoreContractTests;`). Each trait declares an `abstract protected function makeStore(): ChainStore;` that the host class implements.

- [ ] **Step 2: Port `ChainStoreContractTests`**

Copy the file to `tests/Contract/ChainStoreContractTests.php` with namespace `Fissible\AttestLaravel\Tests\Contract`. Add the attribution comment at the top:

```php
<?php
declare(strict_types=1);

/**
 * Ported by value from fissible/attest tests/Chain/ChainStoreContractTests.php.
 *
 * TODO(v1.0): once `Fissible\Attest\Testing\*` is extracted as a
 * shipped namespace in core, replace this port with a `require-dev`
 * dependency and `use` the upstream trait. Until then, keep this file
 * in sync with the upstream source by reviewing core's diff against
 * the SHA recorded below.
 *
 * Synced from upstream commit: <SHA at port time — fill in step 5>
 */

namespace Fissible\AttestLaravel\Tests\Contract;
```

Adjust namespaces of any `use` statements inside the trait — most reference `Fissible\Attest\*` which stay as-is.

- [ ] **Step 3: Port `AnchorClaimStoreContractTests`**

Same procedure for `tests/Contract/AnchorClaimStoreContractTests.php`.

- [ ] **Step 4: Wire the traits into the existing store tests**

In `tests/Stores/EloquentChainStoreReadTest.php`, add `use ChainStoreContractTests;` and implement the trait's abstract `makeStore()` method:

```php
use Fissible\AttestLaravel\Tests\Contract\ChainStoreContractTests;
// ...
final class EloquentChainStoreReadTest extends TestCase
{
    use RefreshDatabase;
    use ChainStoreContractTests;

    protected function makeStore(): \Fissible\Attest\Chain\ChainStore
    {
        return new EloquentChainStore(DB::connection(), $this->dummyLocker(), $this->dummyEvents());
    }
    // ... existing tests
}
```

Same pattern for `EloquentAnchorClaimStoreTest`.

- [ ] **Step 5: Record the upstream SHA**

```
cd ~/lib/fissible/attest && git rev-parse HEAD
```

Paste the returned SHA into the `Synced from upstream commit:` line at the top of each ported file.

- [ ] **Step 6: Run the full suite**

```
vendor/bin/phpunit
```

The ported contract tests add ~15-25 cases each. Expected: all pass. If any contract test fails, the store implementation has a real semantic bug and must be fixed (do NOT modify the contract trait to make it pass).

- [ ] **Step 7: Commit**

```
git add tests/Contract/ tests/Stores/
git commit -m "test(contract): port ChainStore + AnchorClaimStore contract suites from core"
```

---

## Task 4.13: `AttestServiceProvider`, `AttestRegistry`, `Attest` facade, config

**Why:** Bind everything in the container, publish config + migrations, force UTC session timezone, wire the facade. Last big foundation piece.

**Files:**
- Create: `config/attest.php`
- Modify: `src/AttestServiceProvider.php`
- Create: `src/Support/AttestRegistry.php`
- Create: `src/Facades/Attest.php`
- Create: `tests/AttestServiceProviderTest.php`

- [ ] **Step 1: Create `config/attest.php`**

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

    /** Total wall-clock seconds the locker will wait before throwing
     *  ChainLockUnavailable. */
    'lock_timeout_seconds' => 10,

    /** Postgres locker polling interval (microseconds). */
    'postgres_lock_poll_us' => 50_000,

    /** Ed25519 signing material. Both env vars are required when the
     *  Attest facade is used; the registry throws a clear error on
     *  first use if either is missing. */
    'signing_key' => [
        'seed_env'   => 'ATTEST_SIGNING_KEY_SEED',
        'key_id_env' => 'ATTEST_SIGNING_KEY_ID',
    ],

    /** AnchorClaim TTL — reclaimable after this many seconds of being
     *  incomplete. */
    'claim_ttl_seconds' => 3600,
];
```

- [ ] **Step 2: Create `AttestRegistry`**

`src/Support/AttestRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Signing\Signer;

final class AttestRegistry
{
    public function __construct(
        private readonly ChainStore $store,
        private readonly AnchorClaimStore $claimStore,
        private readonly Signer $signer,
    ) {
    }

    /** Return a fresh EvidenceChain on every call. Caching per chain
     *  in a singleton registry would leak across Octane requests and
     *  grow unbounded under multi-tenant workloads. */
    public function chain(string $chainId): EvidenceChain
    {
        return EvidenceChain::open($this->store, $chainId, $this->signer);
    }

    public function store(): ChainStore
    {
        return $this->store;
    }

    public function claimStore(): AnchorClaimStore
    {
        return $this->claimStore;
    }
}
```

- [ ] **Step 3: Create the `Attest` facade**

`src/Facades/Attest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Facades;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\AttestLaravel\Support\AttestRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static EvidenceChain chain(string $chainId)
 * @method static ChainStore store()
 * @method static AnchorClaimStore claimStore()
 */
final class Attest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AttestRegistry::class;
    }
}
```

- [ ] **Step 4: Full `AttestServiceProvider`**

Replace `src/AttestServiceProvider.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\Signer;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Stores\EloquentAnchorClaimStore;
use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Stores\Locking\ChainLocker;
use Fissible\AttestLaravel\Stores\Locking\MysqlChainLocker;
use Fissible\AttestLaravel\Stores\Locking\PostgresChainLocker;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Support\AttestRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use ParagonIE\ConstantTime\Base64;

final class AttestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/attest.php', 'attest');

        $this->app->singleton(ChainLocker::class, function (Container $app): ChainLocker {
            $conn = $this->attestConnection($app);
            $driver = $conn->getDriverName();
            $timeout = (int) $app['config']->get('attest.lock_timeout_seconds', 10);
            return match ($driver) {
                'sqlite' => new SqliteChainLocker($conn, $timeout),
                'mysql' => new MysqlChainLocker($conn, $timeout),
                'pgsql' => new PostgresChainLocker(
                    $conn,
                    $timeout,
                    (int) $app['config']->get('attest.postgres_lock_poll_us', 50_000),
                ),
                default => throw new \RuntimeException("Unsupported DB driver for attest: $driver"),
            };
        });

        $this->app->singleton(ChainStore::class, function (Container $app): ChainStore {
            return new EloquentChainStore(
                $this->attestConnection($app),
                $app->make(ChainLocker::class),
                $app->make(Dispatcher::class),
            );
        });

        $this->app->singleton(AnchorClaimStore::class, function (Container $app): AnchorClaimStore {
            return new EloquentAnchorClaimStore($this->attestConnection($app));
        });

        $this->app->singleton(Signer::class, function (Container $app): Signer {
            $cfg = $app['config']->get('attest.signing_key');
            $seedEnv = $cfg['seed_env'] ?? 'ATTEST_SIGNING_KEY_SEED';
            $keyIdEnv = $cfg['key_id_env'] ?? 'ATTEST_SIGNING_KEY_ID';
            $seedBase64 = getenv($seedEnv) ?: '';
            $keyId = getenv($keyIdEnv) ?: '';
            if ($seedBase64 === '' || $keyId === '') {
                throw new \RuntimeException(sprintf(
                    'Attest signer requires env vars %s and %s', $seedEnv, $keyIdEnv,
                ));
            }
            $seed = Base64::decode($seedBase64, strictPadding: true);
            return new SodiumSigner(KeyPair::fromSeed($seed), keyId: $keyId);
        });

        $this->app->singleton(AttestRegistry::class, function (Container $app): AttestRegistry {
            return new AttestRegistry(
                $app->make(ChainStore::class),
                $app->make(AnchorClaimStore::class),
                $app->make(Signer::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/attest.php' => $this->app->configPath('attest.php'),
        ], 'attest-config');
        $this->publishes([
            __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'attest-migrations');

        // Force UTC on the attest connection's DB session so the
        // Y-m-d H:i:s.u strings Timestamp emits are interpreted as UTC
        // on insert and selected as UTC on read. SQLite has no session
        // timezone; MySQL and Postgres do.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $attestName = $this->app['config']->get('attest.connection')
                ?? $this->app['config']->get('database.default');
            if ($event->connectionName !== $attestName) {
                return;
            }
            $this->forceUtc($event->connection);
        });
    }

    private function attestConnection(Container $app): Connection
    {
        $name = $app['config']->get('attest.connection') ?? $app['config']->get('database.default');
        $conn = $app['db']->connection($name);
        assert($conn instanceof Connection);
        $this->forceUtc($conn);
        return $conn;
    }

    private function forceUtc(Connection $conn): void
    {
        switch ($conn->getDriverName()) {
            case 'mysql':
                $conn->statement("SET time_zone = '+00:00'");
                break;
            case 'pgsql':
                $conn->statement("SET TIME ZONE 'UTC'");
                break;
            // SQLite stores naive strings; no session timezone applies.
        }
    }
}
```

- [ ] **Step 5: Write the service-provider test**

`tests/AttestServiceProviderTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\AttestLaravel\Facades\Attest;
use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Support\AttestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64;

final class AttestServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Provide the signer env so Signer bindings resolve.
        putenv('ATTEST_SIGNING_KEY_SEED=' . Base64::encode(random_bytes(32)));
        putenv('ATTEST_SIGNING_KEY_ID=k1');
    }

    protected function tearDown(): void
    {
        putenv('ATTEST_SIGNING_KEY_SEED');
        putenv('ATTEST_SIGNING_KEY_ID');
        parent::tearDown();
    }

    public function test_container_resolves_chain_store(): void
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(EloquentChainStore::class, $store);
    }

    public function test_container_resolves_anchor_claim_store(): void
    {
        $store = $this->app->make(AnchorClaimStore::class);
        self::assertInstanceOf(\Fissible\AttestLaravel\Stores\EloquentAnchorClaimStore::class, $store);
    }

    public function test_facade_returns_fresh_evidence_chain_per_call(): void
    {
        $a = Attest::chain('tenant:5');
        $b = Attest::chain('tenant:5');
        self::assertNotSame($a, $b);   // identity differs
        self::assertSame('tenant:5', $a->chainId);
        self::assertSame('tenant:5', $b->chainId);
    }

    public function test_facade_resolves_attest_registry(): void
    {
        self::assertInstanceOf(AttestRegistry::class, $this->app->make(AttestRegistry::class));
    }

    public function test_records_through_facade_end_to_end(): void
    {
        if (getenv('DB_CONNECTION') !== false && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->markTestSkipped('end-to-end facade test runs against sqlite');
        }
        $signed = Attest::chain('tenant:5')->record('app.event', ['x' => 1]);
        self::assertSame(1, $signed->envelope->seq);
        self::assertSame(1, DB::table('attest_envelopes')->count());
    }

    public function test_signer_binding_throws_when_env_missing(): void
    {
        putenv('ATTEST_SIGNING_KEY_SEED');
        putenv('ATTEST_SIGNING_KEY_ID');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ATTEST_SIGNING_KEY/i');
        $this->app->make(\Fissible\Attest\Signing\Signer::class);
    }
}
```

- [ ] **Step 6: Run, expect pass**

```
vendor/bin/phpunit --filter AttestServiceProviderTest
vendor/bin/phpunit
```

- [ ] **Step 7: PHPStan**

```
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 8: Commit**

```
git add config/ src/AttestServiceProvider.php src/Support/AttestRegistry.php src/Facades/Attest.php tests/AttestServiceProviderTest.php
git commit -m "feat(provider): full ServiceProvider with UTC session enforcement + Attest facade"
```

---

## Task 4.14: Concurrency test (`pcntl_fork`)

**Why:** Spec §13.4 requires 8 workers × 100 envelopes hammering one chain_id, asserting linear chain. Mirrors core's `FileChainStoreConcurrencyTest`. SQLite path asserts global serialization (because its lock is database-wide); MySQL and Postgres paths exercise true per-chain semantics.

**Files:**
- Create: `tests/Concurrency/EloquentConcurrencyTest.php`

- [ ] **Step 1: Write the test**

`tests/Concurrency/EloquentConcurrencyTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Concurrency;

use Fissible\Attest\Chain\AppendContext;
use Fissible\Attest\Envelope\EvidenceEnvelope;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Stores\Locking\MysqlChainLocker;
use Fissible\AttestLaravel\Stores\Locking\PostgresChainLocker;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class EloquentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const WORKERS = 8;
    private const ENVELOPES_PER_WORKER = 100;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }
        if (getenv('DB_CONNECTION') === 'sqlite' && PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('sqlite + fork is brittle outside Linux; CI runs Linux sqlite');
        }
    }

    public function test_concurrent_appends_produce_linear_chain(): void
    {
        $seedKp = KeyPair::generate();
        $seedBytes = $seedKp->seed;  // share via the test fixture so each child re-creates the same signer
        $chainId = 'concurrency-test';

        $pids = [];
        for ($w = 0; $w < self::WORKERS; $w++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // Child: reconnect DB (PDO handles don't survive forks).
                DB::purge();
                $store = $this->makeStore();
                $kp = KeyPair::fromSeed($seedBytes);
                $signer = new SodiumSigner($kp, keyId: 'k1');
                for ($i = 0; $i < self::ENVELOPES_PER_WORKER; $i++) {
                    try {
                        $store->append($chainId, function (AppendContext $ctx) use ($signer, $w, $i) {
                            $env = new EvidenceEnvelope(
                                id: sprintf('01H%01d%022d', $w, $i),
                                chain: $ctx->chainId,
                                seq: $ctx->sequence,
                                ts: $ctx->timestampIso8601,
                                type: 'app.event',
                                payload: ['w' => $w, 'i' => $i],
                                prevHash: $ctx->prevHash,
                                keyId: 'k1',
                                sigAlg: 'ed25519',
                            );
                            return SignedEnvelope::sign($env, $signer);
                        });
                    } catch (\Fissible\Attest\Chain\ChainLockUnavailable) {
                        // Retry on lock timeout — concurrency is the test target.
                        $i--;
                        usleep(10_000);
                    }
                }
                exit(0);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status), "worker pid=$pid exited non-zero");
        }

        $expected = self::WORKERS * self::ENVELOPES_PER_WORKER;
        $count = DB::table('attest_envelopes')->where('chain_id', $chainId)->count();
        self::assertSame($expected, $count, "expected $expected envelopes after concurrent run, got $count");

        // Linear chain: sequences 1..N with no gaps, each prev_hash matches the prior self_hash.
        $rows = DB::table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->orderBy('sequence')
            ->get(['sequence', 'self_hash', 'prev_hash']);
        $prev = null;
        foreach ($rows as $i => $row) {
            self::assertSame($i + 1, (int) $row->sequence, "sequence gap at index $i");
            self::assertSame($prev, $row->prev_hash, "prev_hash mismatch at seq " . ($i + 1));
            $prev = $row->self_hash;
        }
    }

    private function makeStore(): EloquentChainStore
    {
        $conn = DB::connection();
        $locker = match ($conn->getDriverName()) {
            'sqlite' => new SqliteChainLocker($conn, 30),
            'mysql' => new MysqlChainLocker($conn, 30),
            'pgsql' => new PostgresChainLocker($conn, 30, 50_000),
            default => throw new \RuntimeException('unsupported driver'),
        };
        return new EloquentChainStore($conn, $locker, new \Illuminate\Events\Dispatcher());
    }
}
```

- [ ] **Step 2: Run against SQLite (file-backed, since `:memory:` is per-process)**

For SQLite, the in-memory database doesn't survive forks. Use a temporary file via env override during the test:

```
DB_DATABASE=/tmp/attest-concurrency.sqlite DB_CONNECTION=sqlite vendor/bin/phpunit --filter EloquentConcurrencyTest
```

The `defineEnvironment` in `tests/TestCase.php` reads `DB_DATABASE` for SQLite as well — adjust the sqlite branch to use the env var if set:

```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => getenv('DB_DATABASE') ?: ':memory:',
    'foreign_key_constraints' => true,
],
```

(Update `tests/TestCase.php` accordingly as part of this task.)

- [ ] **Step 3: Run against MySQL and Postgres in CI**

These are exercised by the matrix in Task 4.15.

- [ ] **Step 4: Commit**

```
git add tests/Concurrency/EloquentConcurrencyTest.php tests/TestCase.php
git commit -m "test(concurrency): 8 workers x 100 envelopes per chain — assert linear chain"
```

---

## Task 4.15: CI workflow with database matrix

**Why:** Spec §14. Per spec §17: PHP 8.2/8.3/8.4 × SQLite/MySQL 8/Postgres 16. SQLite also on macOS; MySQL and Postgres Linux-only via service containers.

**Files:**
- Modify: `.github/workflows/ci.yml`
- Create: `.github/workflows/release.yml` (reusable workflow wrapper, same pattern as `fissible/attest`)

- [ ] **Step 1: Replace `ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test:
    name: test (php${{ matrix.php }}, ${{ matrix.db }}, ${{ matrix.os }})
    runs-on: ${{ matrix.os }}
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3', '8.4']
        db: ['sqlite']
        os: ['ubuntu-latest', 'macos-latest']
        include:
          - php: '8.2'
            db: 'mysql8'
            os: 'ubuntu-latest'
          - php: '8.3'
            db: 'mysql8'
            os: 'ubuntu-latest'
          - php: '8.4'
            db: 'mysql8'
            os: 'ubuntu-latest'
          - php: '8.2'
            db: 'pgsql16'
            os: 'ubuntu-latest'
          - php: '8.3'
            db: 'pgsql16'
            os: 'ubuntu-latest'
          - php: '8.4'
            db: 'pgsql16'
            os: 'ubuntu-latest'

    services:
      mysql:
        image: ${{ matrix.db == 'mysql8' && 'mysql:8.0' || '' }}
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: attest
        ports: ['3306:3306']
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -proot"
          --health-interval=10s --health-timeout=5s --health-retries=10
      postgres:
        image: ${{ matrix.db == 'pgsql16' && 'postgres:16' || '' }}
        env:
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: attest
        ports: ['5432:5432']
        options: >-
          --health-cmd="pg_isready -U postgres" --health-interval=10s
          --health-timeout=5s --health-retries=10

    steps:
      - uses: actions/checkout@v4
        with:
          path: attest-laravel

      - uses: actions/checkout@v4
        with:
          repository: fissible/attest
          ref: main
          path: attest

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: sodium, zip, json, pdo_sqlite, pdo_mysql, pdo_pgsql, pcntl
          coverage: none

      - name: Validate composer.json
        working-directory: attest-laravel
        run: composer validate --strict

      - name: Install
        working-directory: attest-laravel
        run: composer install --prefer-dist --no-progress

      - name: PHPStan
        working-directory: attest-laravel
        run: vendor/bin/phpstan analyse --no-progress

      - name: PHPUnit (sqlite)
        if: matrix.db == 'sqlite'
        working-directory: attest-laravel
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'
        run: vendor/bin/phpunit --colors=always

      - name: PHPUnit (mysql8)
        if: matrix.db == 'mysql8'
        working-directory: attest-laravel
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_PORT: 3306
          DB_DATABASE: attest
          DB_USERNAME: root
          DB_PASSWORD: root
        run: vendor/bin/phpunit --colors=always

      - name: PHPUnit (pgsql16)
        if: matrix.db == 'pgsql16'
        working-directory: attest-laravel
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: attest
          DB_USERNAME: postgres
          DB_PASSWORD: postgres
        run: vendor/bin/phpunit --colors=always

      - name: PHPUnit concurrency (sqlite, file-backed)
        if: matrix.db == 'sqlite' && matrix.os == 'ubuntu-latest'
        working-directory: attest-laravel
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: /tmp/attest-concurrency.sqlite
        run: |
          rm -f /tmp/attest-concurrency.sqlite
          vendor/bin/phpunit --filter EloquentConcurrencyTest --colors=always
```

The two `actions/checkout@v4` calls pull both this repo and `fissible/attest` into sibling directories so the path repository in composer.json (`../attest`) resolves.

- [ ] **Step 2: Add the release workflow wrapper**

`.github/workflows/release.yml`:

```yaml
name: Release
on:
  push:
    tags: ['v*']
permissions:
  contents: write
jobs:
  release:
    uses: fissible/.github/.github/workflows/release.yml@main
```

- [ ] **Step 3: Commit**

```
git add .github/workflows/ci.yml .github/workflows/release.yml
git commit -m "ci: full DB matrix (sqlite/mysql8/pgsql16) x php (8.2/8.3/8.4)"
```

- [ ] **Step 4: Push and watch CI**

```
git push
```

Wait for the matrix to go green before proceeding to Task 4.16. If any cell fails, investigate per-driver semantics (lock timeout values, timezone enforcement, dialect-specific DDL); the contract tests should pin most issues.

---

## Task 4.16: README, CHANGELOG, VERSION bump, release.sh, tag

**Files:**
- Modify: `README.md`
- Create: `release.sh` (copy from `fissible/.github`)
- Create: `.cliff.toml` (copy from `fissible/.github`)
- Create: `CHANGELOG.md`
- Create: `VERSION`

- [ ] **Step 1: README — install, migrate, config, example**

Replace `README.md`:

```markdown
# fissible/attest-laravel

> Laravel adapter for [`fissible/attest`](https://github.com/fissible/attest): Eloquent storage, console commands, scheduled anchoring, events.

**Status:** Alpha — under active development. API stabilizes at v1.0.

## Install

```bash
composer require fissible/attest-laravel
```

Then publish migrations and config, and migrate:

```bash
php artisan vendor:publish --tag=attest-config
php artisan vendor:publish --tag=attest-migrations
php artisan migrate
```

(Migrations also auto-load if you don't want to publish them.)

## Configure

Set these env vars:

```env
ATTEST_CONNECTION=mysql           # or pgsql or sqlite (defaults to the app's default connection)
ATTEST_SIGNING_KEY_SEED=<base64>  # 32-byte Ed25519 seed
ATTEST_SIGNING_KEY_ID=app-prod-2026-01
```

## Use

```php
use Fissible\AttestLaravel\Facades\Attest;

$envelope = Attest::chain('tenant:5')->record('cms.entry.published', [
    'entry_id' => 42,
    'checksum' => 'sha256:abc...',
    'actor_id' => 7,
]);
```

Each `record()` opens a per-chain write lock (per-row on MySQL/Postgres; database-wide on SQLite), reads the chain tail, builds an `AppendContext`, signs the envelope with the configured Ed25519 key, validates the context, persists into `attest_envelopes`, and dispatches an `EnvelopeRecorded` event after the transaction commits.

## Database support

- SQLite — single-host / single-writer. Write lock is database-wide.
- MySQL 8 — per-chain `GET_LOCK`. Multi-writer safe.
- PostgreSQL 16 — per-chain `pg_try_advisory_xact_lock`. Multi-writer safe.

## License

MIT
```

- [ ] **Step 2: Copy fissible release tooling**

```
cp /Users/allenmccabe/lib/fissible/.github/release.sh ./release.sh
cp /Users/allenmccabe/lib/fissible/.github/.cliff.toml ./.cliff.toml
chmod +x release.sh
```

- [ ] **Step 3: Create CHANGELOG**

`CHANGELOG.md`:

```markdown
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
```

- [ ] **Step 4: Set VERSION**

```
echo '0.2.0-alpha' > VERSION
```

- [ ] **Step 5: Commit**

```
git add README.md release.sh .cliff.toml CHANGELOG.md VERSION
git commit -m "docs: README + CHANGELOG + release tooling for v0.2.0-alpha"
```

- [ ] **Step 6: Tag and push**

`release.sh` doesn't handle `-alpha` suffix versions out of the box (same issue as `fissible/attest`). Use the manual procedure:

```
git tag -a v0.2.0-alpha -m "v0.2.0-alpha"
git push && git push --tags
```

The pushed tag triggers `.github/workflows/release.yml` → the reusable `fissible/.github/release.yml`, which creates the GitHub Release automatically.

---

## Done Criteria

- All migrations apply cleanly against SQLite/MySQL 8/Postgres 16 in CI.
- `vendor/bin/phpunit` green across the full matrix.
- `vendor/bin/phpstan analyse --no-progress` clean.
- `Attest::chain('tenant:5')->record(...)` works end-to-end against SQLite locally.
- Concurrency test passes with 8 workers × 100 envelopes on MySQL and Postgres; SQLite asserts global serialization without errors.
- `EnvelopeRecorded` fires exactly once per successful `append()`, after commit, and never on rollback.
- README has install + migrate + config + example.
- Tag `v0.2.0-alpha` pushed; GitHub Release auto-created.
