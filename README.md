# fissible/attest-laravel

> Laravel adapter for [`fissible/attest`](https://github.com/fissible/attest): Eloquent storage, Artisan commands, queue-ready anchoring, and events.

**Status:** Alpha. API stabilizes at v1.0.

---

## What is this, in plain terms?

This package is the **Laravel integration** for [`fissible/attest`](https://github.com/fissible/attest),
a **tamper-evident logbook for the important things your application does** — contract
approvals, publishes, permission grants, anything you might one day need to *prove* happened and
*prove* wasn't edited afterward.

The core library handles the cryptography (each event is signed and chained, so any later
tampering is detectable, and batches can be "notarized" against the Bitcoin blockchain to prove
*when* they existed). This package wires all of that into Laravel so you barely have to think
about it:

- **Store** evidence in your database via Eloquent (`attest_envelopes`), with correct per-driver
  write locking on SQLite, MySQL, and PostgreSQL.
- **Record** an event with a single facade call.
- **Anchor, verify, export, and audit** with Artisan commands — runnable by hand, on a queue, or
  on a schedule.
- **Import** existing append-only JSONL logs into evidence chains without duplicates.

> New to attest? Read the [core README](https://github.com/fissible/attest#readme) first for the
> plain-language "what and why," including the worked dispute example. This page assumes you want
> it inside a Laravel app.

## The 30-second example

Record an event when something important happens:

```php
use Fissible\AttestLaravel\Facades\Attest;

Attest::chain('tenant:5')->record('contract.approved', [
    'contract_id' => 'C-2026-014',
    'approved_by' => 'user:7',
    'amount'      => 50_000,
]);
```

Later, prove that chain is intact and signed by a key you trust:

```bash
php artisan attest:verify --chain=tenant:5 --trusted-key=prod=<base64-public-key>
```

A clean chain exits `0`. A tampered or unsigned chain fails — so "the log wasn't edited" stops
being something you ask people to take on faith.

Everything below is detail you can read when you need it.

---

## Install

```bash
composer require fissible/attest-laravel
php artisan vendor:publish --tag=attest-config
php artisan vendor:publish --tag=attest-migrations
php artisan migrate
```

Requires PHP `^8.2` and Laravel 11 or 12. Migrations also auto-load if you do not want to
publish them.

## Configure

Set the attest database connection and signing key environment variables:

```env
ATTEST_CONNECTION=mysql
ATTEST_SIGNING_KEY_SEED=<base64-32-byte-ed25519-seed>
ATTEST_SIGNING_KEY_ID=station-prod-2026-01
```

Useful operational defaults:

```env
ATTEST_DEFAULT_CHAIN=tenant:5
ATTEST_DEFAULT_DRIVER=local-only
ATTEST_ANCHOR_QUEUE=anchors
ATTEST_MIN_ANCHOR=local_only
```

OpenTimestamps anchoring and Bitcoin header verification use optional PSR-18/PSR-7 wiring from
core. This package suggests `guzzlehttp/guzzle` and `guzzlehttp/psr7`; install them when you want
calendar or header-provider commands to create HTTP clients from config.

## Record

```php
use Fissible\AttestLaravel\Facades\Attest;

$envelope = Attest::chain('tenant:5')->record('cms.entry.published', [
    'entry_id' => 42,
    'checksum' => 'sha256:abc...',
    'actor_id' => 7,
]);
```

Each `record()` opens a per-chain write lock, reads the chain tail, builds an `AppendContext`,
signs the envelope with the configured Ed25519 key, validates the context, persists into
`attest_envelopes`, and dispatches an `EnvelopeRecorded` event after the transaction commits.

## Import JSONL

Use `GenericJsonlImporter` when an application already has append-only JSONL and wants to replay
it into an attest chain without duplicate envelopes:

```php
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Canonical\JcsEncoder;
use Fissible\Attest\Signing\Signer;
use Fissible\AttestLaravel\Import\EloquentImportMarkerTrait;
use Fissible\AttestLaravel\Import\GenericJsonlImporter;
use Fissible\AttestLaravel\Import\JsonlImportContext;
use Fissible\AttestLaravel\Import\JsonlImportOptions;
use Illuminate\Database\ConnectionInterface;

final class UpdaterAuditImporter extends GenericJsonlImporter
{
    use EloquentImportMarkerTrait;

    public function __construct(
        ChainStore $store,
        Signer $signer,
        private readonly ConnectionInterface $connection,
    ) {
        parent::__construct($store, $signer);
    }

    protected function importer(): string
    {
        return 'station.updater.audit.global.v1';
    }

    protected function importMarkerConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    protected function parseLine(string $line, int $lineNumber): ?array
    {
        $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \RuntimeException("Line $lineNumber is not a JSON object.");
        }
        return $decoded;
    }

    protected function chainIdFor(array $parsed, JsonlImportContext $context): string
    {
        return 'updater:global';
    }

    protected function contentHashFor(array $parsed, JsonlImportContext $context): string
    {
        return hash('sha256', JcsEncoder::encode($parsed));
    }

    protected function buildPayload(array $parsed, JsonlImportContext $context): array
    {
        return $parsed;
    }
}
```

`importer()` is the durable marker namespace stored in `attest_import_markers`. Include the
logical importer, upstream source/feed identity, and schema version; do not use only the PHP
class name. `contentHashFor()` must return a stable lower-case SHA-256 digest for the logical
source record, not a line number or byte offset.

The importer uses `ChainStore::append()` directly and writes the marker inside the append
callback. With `EloquentChainStore`, the marker and envelope append share one transaction, so a
failed append does not strand a marker. Reruns skip existing markers; malformed records fail
fast by default. Pass `new JsonlImportOptions(continueOnError: true)` to collect diagnostics and
continue past bad lines.

Station's updater bridge is a consumer-side sidecar over its existing runbook audit JSONL. This
package ships only the generic importer primitives.

## Anchor

Anchor a known range immediately:

```bash
php artisan attest:anchor --chain=tenant:5 --from=1 --to=100 --sync
```

Dispatch the queueable `AnchorPendingBatch` instead of anchoring inline:

```bash
php artisan attest:anchor --chain=tenant:5 --from=1 --queue=anchors
```

The package does not auto-register schedules. Add scheduling in your app when you want periodic
anchoring:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('attest:anchor --chain=tenant:5')->hourly();
```

For OpenTimestamps:

```bash
php artisan attest:anchor \
    --chain=tenant:5 \
    --from=1 \
    --to=100 \
    --driver=opentimestamps \
    --calendar-url=https://calendar.opentimestamps.org \
    --sync
```

Upgrade pending OpenTimestamps receipts:

```bash
php artisan attest:upgrade --chain=tenant:5 --all-pending
```

## Verify

Verify chain integrity and trusted signatures:

```bash
php artisan attest:verify --chain=tenant:5 --trusted-key=prod=<base64-public-key>
```

Require an anchor threshold:

```bash
php artisan attest:verify \
    --chain=tenant:5 \
    --trusted-key=prod=<base64-public-key> \
    --min-anchor=local_only
```

Accepted `--min-anchor` values are `local_only`, `pending`, `upgraded_no_headers`,
`remote_header_confirmed`, and `bitcoin_verified`.

## Bundles

Export a portable proof bundle:

```bash
php artisan attest:bundle:export \
    --chain=tenant:5 \
    --from=1 \
    --to=100 \
    --out=storage/app/tenant-5.attest
```

Verify a bundle:

```bash
php artisan attest:bundle:verify \
    --bundle=storage/app/tenant-5.attest \
    --trusted-key=prod=<base64-public-key> \
    --min-anchor=local_only
```

Claimed keys included in bundles are informational. They are never trusted automatically by
`attest:bundle:verify`.

## Integrity Audit

Audit Eloquent read-side index columns against raw canonical envelopes:

```bash
php artisan attest:integrity:audit --chain=tenant:5
```

Exit codes are `0` for clean, `1` for invalid options, and `4` for detected drift.

## Database Support

- SQLite: single-host / single-writer. Write lock is database-wide.
- MySQL 8: per-chain `GET_LOCK`. Multi-writer safe.
- PostgreSQL 16: per-chain `pg_try_advisory_xact_lock`. Multi-writer safe.

## License

MIT
