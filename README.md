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
ATTEST_SIGNING_KEY_ID=station-prod-2026-01
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
