# fissible/attest-laravel

> Laravel adapter for [`fissible/attest`](https://github.com/fissible/attest): Eloquent storage, Artisan commands, queue-ready anchoring, and events.

**Status:** Alpha. API stabilizes at v1.0.

## Install

```bash
composer require fissible/attest-laravel
php artisan vendor:publish --tag=attest-config
php artisan vendor:publish --tag=attest-migrations
php artisan migrate
```

Migrations also auto-load if you do not want to publish them.

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

OpenTimestamps anchoring and Bitcoin header verification use optional PSR-18/PSR-7 wiring from core. This package suggests `guzzlehttp/guzzle` and `guzzlehttp/psr7`; install them when you want calendar or header-provider commands to create HTTP clients from config.

## Record

```php
use Fissible\AttestLaravel\Facades\Attest;

$envelope = Attest::chain('tenant:5')->record('cms.entry.published', [
    'entry_id' => 42,
    'checksum' => 'sha256:abc...',
    'actor_id' => 7,
]);
```

Each `record()` opens a per-chain write lock, reads the chain tail, builds an `AppendContext`, signs the envelope with the configured Ed25519 key, validates the context, persists into `attest_envelopes`, and dispatches an `EnvelopeRecorded` event after the transaction commits.

## Anchor

Anchor a known range immediately:

```bash
php artisan attest:anchor --chain=tenant:5 --from=1 --to=100 --sync
```

Dispatch the queueable `AnchorPendingBatch` instead of anchoring inline:

```bash
php artisan attest:anchor --chain=tenant:5 --from=1 --queue=anchors
```

The package does not auto-register schedules. Add scheduling in your app when you want periodic anchoring:

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

Accepted `--min-anchor` values are `local_only`, `pending`, `upgraded_no_headers`, `remote_header_confirmed`, and `bitcoin_verified`.

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

Claimed keys included in bundles are informational. They are never trusted automatically by `attest:bundle:verify`.

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
