# fissible/attest-laravel

> Laravel adapter for [`fissible/attest`](https://github.com/fissible/attest): Eloquent storage, Artisan commands, queue-ready anchoring, and events.

**Status:** Stable — v1.0+. The public API (types marked `@api`) follows semantic versioning; see [`STABILITY.md`](STABILITY.md). Built on the stable [`fissible/attest`](https://github.com/fissible/attest) 1.x core. Anchoring inherits core's `@experimental` status in `1.x`.

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

Requires PHP `^8.2` and Laravel 12 or 13. (Laravel 13 requires PHP `^8.3`; PHP 8.2 is supported
on Laravel 12.) Migrations also auto-load if you do not want to publish them.

## Configure

Set the attest database connection and signing key environment variables:

```env
ATTEST_CONNECTION=mysql
ATTEST_SIGNING_KEY_SEED=<base64-32-byte-ed25519-seed>
ATTEST_SIGNING_KEY_ID=app-prod-2026-01
```

Useful operational defaults:

```env
ATTEST_DEFAULT_CHAIN=tenant:5
ATTEST_DEFAULT_DRIVER=local-only
ATTEST_ANCHOR_QUEUE=anchors
ATTEST_MIN_ANCHOR=local_only
```

Header-provider lookups use a container-bound `Psr\Http\Client\ClientInterface` when one is
bound. The container's PSR-17 request and stream factories are used when available, otherwise
`guzzlehttp/psr7` supplies those factories.

OpenTimestamps anchoring and Bitcoin header verification use optional PSR-18/PSR-7 wiring from
core. This package suggests `guzzlehttp/guzzle` and `guzzlehttp/psr7`; install them when you want
calendar or header-provider commands to create HTTP clients from config.

## Chains, and why the examples say `tenant:5`

A **chain** is one append-only, hash-linked sequence. `Attest::chain('...')` names it, and the
name is your convention — attest never parses it. Everything that matters is scoped to a chain:
sequence numbers, the hash link, the write lock, and verification. Break one chain and the others
are untouched; verify one chain and you have said nothing about the rest.

That scoping is why a multi-tenant application — one deployment serving several customers,
organizations, or workspaces that must not see each other's data — usually opens **one chain per
tenant** rather than one chain for everything:

- **Handoff stays clean.** Evidence gets exported and handed to an auditor, a customer, or a
  court. With a chain per tenant you can hand over one tenant's complete history without
  redacting anyone else's out of it — and redaction would break the hash link anyway.
- **A range is provable.** Anchoring and bundles work over a sequence range. Per-tenant ranges
  mean "here is everything we recorded for you, entries 1–4,000," which a shared chain cannot say
  without disclosing the gaps.
- **Contention and blast radius are bounded.** Appends take a per-chain write lock, and a chain
  that breaks or stalls affects only its own tenant.

`tenant:5` is just that convention written out: a chain holding tenant 5's evidence. Use whatever
reads clearly for your domain — `org:acme`, `workspace:42`, `updater:global` for something that
is genuinely global. Single-tenant applications can happily use one chain per *concern*
(`billing`, `contracts`) instead; nothing here requires tenancy.

Separately, `record()` takes an optional `tenant` value. That is a field on the signed envelope
and an indexed column, not a chain selector — it answers "which tenant did this concern?" for
queries that cross chains, and it is what lets a shared chain stay queryable per tenant. The two
are independent: use the chain id for isolation, the field for attribution.

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

## Query by correlation, subject, or tenant

`record()` accepts optional `subject`, `correlation`, and `tenant` values. They are part of the
signed envelope, and they are also projected into indexed columns so you can find envelopes
without decoding every row in a chain:

```php
use Fissible\AttestLaravel\Models\AttestEnvelope;

Attest::chain('tenant:5')->record(
    type: 'agent.decision',
    payload: ['disposition' => 'permit'],
    subject: 'order:42',
    correlation: $invocationId,
    tenant: 'acme',
);

$envelopes = AttestEnvelope::query()->forCorrelation($invocationId)->get();

foreach ($envelopes as $envelope) {
    $signed = $envelope->signed();   // decode the artifact itself
}
```

`forCorrelation()` and `forSubject()` return oldest-first, ordered by `created_at` with
`envelope_id` breaking ties — `sequence` only orders within a single chain. Neither is
chain-scoped: a correlation id is assigned by the writing application, so an application that
shards one chain per tenant still gets a single answer across chains.

Correlation ids are unique only by your own convention. If you cannot rely on that convention
across tenants, scope the query: `AttestEnvelope::query()->forTenant('acme')->forCorrelation($id)`.

**These columns are a projection, never verifier trust input.** `raw_envelope` holds the only
signed bytes, and that is what `attest:verify` reads. Editing a projection column cannot forge
evidence or make a broken chain verify — but blanking one *can* hide a row from these queries
while the chain still verifies clean. `attest:integrity:audit` compares every projection column
back against the raw envelope and reports drift, so run it on a schedule if you rely on these
queries for audit answers. When completeness matters more than latency, read the chain.

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
        return 'ops.updater.audit.global.v1';
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

Bridges for a specific upstream log belong in the application that owns that log — a sidecar over
its existing JSONL, not something this package can know the shape of. Only the generic importer
primitives ship here.

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

For programmatic verification, resolve the stable verifier from the container:

```php
use Fissible\AttestLaravel\Verification\ChainVerifier;
use Fissible\AttestLaravel\Verification\VerificationRequest;

$result = app(ChainVerifier::class)->verify(new VerificationRequest(
    chainId: 'tenant:5',
    trustedKeys: ['prod=<base64-public-key>'],
));

if ($result->isVerified()) {
    // $result->verifiedThroughSeq is the verified range extent.
}
```

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
