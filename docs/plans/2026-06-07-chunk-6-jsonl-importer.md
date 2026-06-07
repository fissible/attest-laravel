# fissible/attest-laravel - Chunk 6 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the generic Laravel JSONL import surface promised by the core spec: `GenericJsonlImporter` plus `EloquentImportMarkerTrait`, backed by an `attest_import_markers` table. The importer must let consumers replay append-only JSONL into evidence chains without duplicate envelopes and without this package depending on Station.

**Architecture:** `GenericJsonlImporter` is an abstract base that owns the JSONL loop, idempotency checks, envelope construction, signing, and result accounting. Consumer subclasses provide source-specific parsing, chain/type mapping, payload mapping, and stable record identity. The base uses `ChainStore::append()` directly instead of `EvidenceChain::record()` so marker insertion can happen inside the append callback and therefore inside `EloquentChainStore`'s transaction. `EloquentImportMarkerTrait` supplies the marker implementation with `insertOrIgnore()` against the configured marker table.

**Reference consumer:** Station's updater bridge. The existing Station `App\Updater\Runbook\AuditLogger` writes framework-free JSONL rows shaped like `{"ts": "...", "event": "...", ...context}` to `storage/app/update-state/audit.jsonl`. The bridge remains a Station-side sidecar: it replays that log into chain `updater:global` only when Composer, Laravel, and signer access are healthy. This adapter chunk ships only the reusable importer primitives.

**Tech Stack:** PHP 8.2+, Laravel 11/12 (`illuminate/database`, `illuminate/support`), `orchestra/testbench`, PHPUnit 11, PHPStan 1.x. Depends on `fissible/attest` core `^0.4.2-alpha`.

**Spec:** `~/lib/fissible/station/docs/superpowers/specs/2026-05-25-fissible-attest-design.md` sections 14, 15, and 17; Chunk 4 Eloquent foundation spec; Chunk 5 command/job plan.

**Tag at completion:** `v0.4.0-alpha`.

---

## Design decisions locked for Chunk 6

- **Source identity:** The durable marker namespace is the `importer` column. It must include the logical importer, upstream source/feed identity, and schema version, for example `station.updater.audit.global.v1`. Do not default this to only the PHP class name. The base validates it as non-empty and <= 191 chars.
- **Record identity:** `contentHashFor()` returns a stable lower-case 64-character SHA-256 hex digest for the logical source record. It must not be a line number or byte offset. Reordered files and resumed imports must still identify the same record.
- **Marker semantics:** A marker is the checkpoint. Chunk 6 does not persist a line-offset checkpoint. A record is complete only after `(importer, content_hash) -> envelope_id` has been inserted in the same transaction as the envelope append.
- **Idempotency:** Rerunning the same import skips existing markers. Duplicate records inside the same file also skip after the first successful append.
- **Concurrent duplicate handling:** If another process inserts the same marker between the preflight `hasImported()` and the append callback, `markImported()` returns false, the callback throws an internal `AlreadyImported` exception, and the append transaction rolls back without writing a duplicate envelope.
- **Partial failure:** Default behavior is fail-fast. Previously committed records remain committed; the failed record is not marked. An opt-in `continueOnError` option may collect diagnostics and continue, but failed records never get markers.
- **Parse skip vs parse failure:** `parseLine()` may return `null` to intentionally skip blank/comment/non-domain rows. Invalid JSON or invalid domain data should throw an import exception and follow the partial-failure policy.
- **Envelope type and payload:** The default event type is `attest.import.jsonl.recorded.v1`. Subclasses may override it. The base wraps mapped payloads with import provenance:

```php
[
    'source' => [
        'importer' => '<marker namespace>',
        'content_hash' => '<sha256 hex>',
        'line_number' => 123,
    ],
    'record' => $mappedPayload,
]
```

- **Transactions:** The atomicity guarantee relies on `EloquentChainStore` invoking the append callback inside its locker transaction. This is the supported store for `EloquentImportMarkerTrait`. Do not claim the same guarantee for arbitrary external `ChainStore` implementations.
- **Extensibility:** The package ships no Station classes. Station will implement its updater bridge by subclassing `GenericJsonlImporter` and using the trait.

---

## Assumed from prior chunks

From Chunk 4:

- `EloquentChainStore` implements `ChainStore` and persists raw signed envelope bytes.
- Per-driver lockers wrap append callbacks in a transaction for SQLite, MySQL, and Postgres.
- `Timestamp` emits UTC microsecond strings for Eloquent-side metadata.
- Migrations are auto-loaded by `AttestServiceProvider`.

From Chunk 5:

- `AttestServiceProvider` binds `ChainStore`, `Signer`, and command support services.
- Guzzle remains optional and unrelated to imports.
- `README`, `CHANGELOG`, and `VERSION` follow the package release rhythm.

From core:

- `ChainStore::append()`, `AppendContext`, `EvidenceEnvelope`, `SignedEnvelope::sign()`, `PayloadValidator`, and `Signer` are available and stable.

---

## File Structure

### New files

```
src/Import/GenericJsonlImporter.php                        (Task 6.3)
src/Import/EloquentImportMarkerTrait.php                   (Task 6.2)
src/Import/JsonlImportOptions.php                          (Task 6.1)
src/Import/JsonlImportResult.php                           (Task 6.1)
src/Import/JsonlImportFailure.php                          (Task 6.1)
src/Import/JsonlImportContext.php                          (Task 6.1)
src/Import/AlreadyImported.php                             (Task 6.3, internal)
src/Import/JsonlImportException.php                        (Task 6.1)
database/migrations/2026_06_07_000003_create_attest_import_markers_table.php (Task 6.0)
tests/Import/EloquentImportMarkerTraitTest.php             (Task 6.2)
tests/Import/GenericJsonlImporterTest.php                  (Tasks 6.3-6.4)
```

### Modified files

```
src/AttestServiceProvider.php                              (Task 6.0, only if migration loading needs no-op test coverage)
README.md                                                  (Task 6.5)
CHANGELOG.md                                               (Task 6.5)
VERSION                                                    (Task 6.5)
```

Do not modify Station in this chunk.

---

## Task 6.0: Import marker schema

**Why:** Idempotent import needs a durable marker keyed by importer/source namespace plus record content hash.

**Files:**
- Create: `database/migrations/2026_06_07_000003_create_attest_import_markers_table.php`
- Modify: `src/AttestServiceProvider.php` only if tests reveal migration loading gaps
- Create/extend: migration tests as needed

- [ ] **Step 1: Add migration**

Create `attest_import_markers`:

```php
Schema::create('attest_import_markers', function (Blueprint $table): void {
    $table->string('importer', 191);
    $table->string('content_hash', 64);
    $table->string('envelope_id', 26);
    $table->timestampTz('imported_at', precision: 6);

    $table->primary(['importer', 'content_hash']);
    $table->index(['importer', 'imported_at']);
});
```

Do not add a foreign key to `attest_envelopes.envelope_id`. The importer writes the marker inside the append callback before `EloquentChainStore` inserts the envelope row, and MySQL/SQLite cannot defer that FK until transaction commit.

- [ ] **Step 2: Tests**

Cover:

- Migration creates the table under SQLite.
- Primary key rejects duplicate `(importer, content_hash)`.
- Same `content_hash` under a different importer namespace is allowed.

- [ ] **Step 3: Verify and commit**

```
vendor/bin/phpunit --filter MigrationsTest
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
git add database/migrations tests src/AttestServiceProvider.php
git commit -m "feat: add attest import marker schema"
```

---

## Task 6.1: Import DTOs and exceptions

**Why:** The import loop needs typed result accounting and failure diagnostics without leaking implementation exceptions to consumers.

**Files:**
- Create: `src/Import/JsonlImportOptions.php`
- Create: `src/Import/JsonlImportResult.php`
- Create: `src/Import/JsonlImportFailure.php`
- Create: `src/Import/JsonlImportContext.php`
- Create: `src/Import/JsonlImportException.php`
- Extend: `tests/Import/GenericJsonlImporterTest.php`

- [ ] **Step 1: Add `JsonlImportOptions`**

Readonly value object:

```php
final readonly class JsonlImportOptions
{
    public function __construct(
        public bool $continueOnError = false,
        public bool $skipBlankLines = true,
    ) {
    }
}
```

- [ ] **Step 2: Add `JsonlImportContext`**

Readonly value object passed to mapping hooks:

```php
final readonly class JsonlImportContext
{
    public function __construct(
        public string $importer,
        public int $lineNumber,
        public string $rawLine,
        public string $contentHash,
    ) {
    }
}
```

Keep source identity in `importer` for Chunk 6. If v1 later needs separate source columns, add them additively.

- [ ] **Step 3: Add result/failure objects**

`JsonlImportResult` should expose at least:

- `linesRead`
- `parsed`
- `imported`
- `skipped`
- `alreadyImported`
- `failed`
- `lastLineNumber`
- `envelopeIds` as `list<string>`
- `failures` as `list<JsonlImportFailure>`

`JsonlImportFailure` should include line number, message, and exception class.

- [ ] **Step 4: Add exception hierarchy**

`JsonlImportException extends RuntimeException` for expected importer failures. The base importer may wrap lower-level throwables into this type with line context, except for the internal duplicate marker exception in Task 6.3.

- [ ] **Step 5: Verify and commit**

```
vendor/bin/phpunit --filter GenericJsonlImporterTest
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
git add src/Import tests/Import
git commit -m "feat: add JSONL import result types"
```

---

## Task 6.2: Eloquent import marker trait

**Why:** Consumers should not reimplement marker table reads and atomic duplicate insertion.

**Files:**
- Create: `src/Import/EloquentImportMarkerTrait.php`
- Create: `tests/Import/EloquentImportMarkerTraitTest.php`

- [ ] **Step 1: Define trait contract**

The trait requires the consuming importer to provide:

```php
abstract protected function importMarkerConnection(): \Illuminate\Database\ConnectionInterface;
abstract protected function importer(): string;
```

`importer()` is the marker namespace described above. It must include source identity and version.

- [ ] **Step 2: Implement marker reads**

```php
protected function hasImported(string $contentHash): bool
```

Behavior:

- Validate `importer()` as non-empty and <= 191 chars.
- Validate `contentHash` as lower-case 64-char hex.
- Query `attest_import_markers` by importer and content hash.

- [ ] **Step 3: Implement marker writes**

```php
protected function markImported(string $contentHash, string $envelopeId): bool
```

Behavior:

- Same validation as reads.
- Insert `importer`, `content_hash`, `envelope_id`, and `imported_at` using `Timestamp`.
- Use `insertOrIgnore()` so duplicate races return false instead of crashing.
- Return true only when one row was inserted.

- [ ] **Step 4: Tests**

Cover:

- `hasImported()` false before marker and true after marker.
- `markImported()` returns false for duplicates.
- Same hash can be marked under two importer namespaces.
- Invalid importer namespace and invalid content hash throw `InvalidArgumentException`.
- `imported_at` is stored with a non-empty timestamp.

- [ ] **Step 5: Verify and commit**

```
vendor/bin/phpunit --filter EloquentImportMarkerTraitTest
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
git add src/Import/EloquentImportMarkerTrait.php tests/Import/EloquentImportMarkerTraitTest.php
git commit -m "feat: add Eloquent import marker trait"
```

---

## Task 6.3: Generic JSONL importer core loop

**Why:** Provide the reusable replay engine that Station and other Laravel apps can subclass.

**Files:**
- Create: `src/Import/GenericJsonlImporter.php`
- Create: `src/Import/AlreadyImported.php`
- Extend: `tests/Import/GenericJsonlImporterTest.php`

- [ ] **Step 1: Constructor and required hooks**

Constructor:

```php
public function __construct(
    protected readonly \Fissible\Attest\Chain\ChainStore $store,
    protected readonly \Fissible\Attest\Signing\Signer $signer,
) {
}
```

Required consumer hooks:

```php
abstract protected function importer(): string;
abstract protected function parseLine(string $line, int $lineNumber): ?array;
abstract protected function buildPayload(array $parsed, JsonlImportContext $context): array;
abstract protected function chainIdFor(array $parsed, JsonlImportContext $context): string;
abstract protected function contentHashFor(array $parsed, JsonlImportContext $context): string;
abstract protected function hasImported(string $contentHash): bool;
abstract protected function markImported(string $contentHash, string $envelopeId): bool;
```

Optional hooks:

```php
protected function typeFor(array $parsed, JsonlImportContext $context): string
protected function subjectFor(array $parsed, JsonlImportContext $context): ?string
protected function correlationFor(array $parsed, JsonlImportContext $context): ?string
protected function tenantFor(array $parsed, JsonlImportContext $context): ?string
protected function newEnvelopeId(): string
```

Defaults:

- `typeFor()` returns `attest.import.jsonl.recorded.v1`.
- subject/correlation/tenant return null.
- `newEnvelopeId()` returns `Ulid::generate()`.

- [ ] **Step 2: Public import methods**

Implement:

```php
public function importFile(string $path, ?JsonlImportOptions $options = null): JsonlImportResult;

/**
 * @param iterable<string> $lines
 */
public function importLines(iterable $lines, ?JsonlImportOptions $options = null): JsonlImportResult;
```

`importFile()` must read lazily and fail clearly when the file cannot be opened. Do not load an entire JSONL file into memory.

- [ ] **Step 3: Import each record**

For each line:

1. Increment line counters.
2. Optionally skip blank lines.
3. Call `parseLine()`. Null means intentional skip.
4. Build an initial context with a temporary content hash placeholder only if needed.
5. Call `contentHashFor()`, validate lower-case SHA-256 hex, and rebuild context with the real hash.
6. If `hasImported()` is true, count as already imported and continue.
7. Resolve chain ID, type, mapped payload, subject, correlation, and tenant.
8. Call `ChainStore::append()` directly.
9. Inside the append callback, build and sign `EvidenceEnvelope`, then call `markImported($contentHash, $envelopeId)`.
10. If marker insertion returns false, throw internal `AlreadyImported`; let the store roll back.
11. Return the signed envelope from the callback.

The signed envelope payload should be:

```php
[
    'source' => [
        'importer' => $this->importer(),
        'content_hash' => $contentHash,
        'line_number' => $lineNumber,
    ],
    'record' => PayloadValidator::ensure($mappedPayload),
]
```

- [ ] **Step 4: Failure handling**

Default fail-fast:

- Stop on the first `JsonlImportException`, invalid hash, invalid chain ID/type, invalid payload, signer error, store error, or parse error.
- Return or throw consistently. Preferred: throw `JsonlImportException` for fail-fast and attach line context.
- Previously appended records stay committed.
- Failed records are not marked.

With `continueOnError: true`:

- Record `JsonlImportFailure`.
- Do not mark the failed record.
- Continue with later lines.
- The final result reports the failure count and diagnostics.

`AlreadyImported` is not a failure; count it as already imported.

- [ ] **Step 5: Tests**

Cover:

- Imports valid JSONL into an evidence chain.
- Rerunning the same JSONL writes no duplicate envelopes.
- Duplicate lines in one file produce one envelope and one already-imported count.
- `parseLine()` returning null increments skipped count.
- Invalid JSON fails fast by default and does not mark the failed line.
- `continueOnError` imports later valid lines and reports diagnostics.
- Same content hash under two importer namespaces can produce two envelopes.
- Same content hash under the same importer but different target chain is still skipped, proving the marker namespace is the duplicate boundary.
- Forced duplicate marker race aborts append with no extra envelope.
- Forced envelope insert failure after marker insertion rolls back the marker. Use the `newEnvelopeId()` seam to duplicate an existing envelope ID and assert no stranded marker remains.

- [ ] **Step 6: Verify and commit**

```
vendor/bin/phpunit --filter GenericJsonlImporterTest
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
git add src/Import tests/Import
git commit -m "feat: add generic JSONL importer"
```

---

## Task 6.4: Station updater bridge fixture coverage

**Why:** The plan's reference consumer is Station's updater audit log. The adapter should prove it supports that shape without importing Station code.

**Files:**
- Extend: `tests/Import/GenericJsonlImporterTest.php`

- [ ] **Step 1: Add a Station-shaped fixture importer**

Inside tests only, create a fixture subclass that:

- Uses importer namespace `station.updater.audit.global.v1`.
- Parses JSON lines with `ts` and `event`.
- Targets chain `updater:global`.
- Uses type `station.updater.audit.v1`.
- Uses content hash `sha256(ts . "\n" . event . "\n" . canonical-json(context-without-ts-event))`.
- Maps payload to:

```php
[
    'ts' => '<source timestamp>',
    'event' => '<event>',
    'context' => [/* remaining fields */],
]
```

- Uses `run_id` as correlation when present.

- [ ] **Step 2: Tests**

Cover:

- Typical Station audit rows import into `updater:global`.
- Replaying the same audit file is a no-op.
- Reordered rows do not duplicate because content hash is record-based.
- A malformed Station row fails without marking it.

- [ ] **Step 3: Verify and commit**

```
vendor/bin/phpunit --filter GenericJsonlImporterTest
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
git add tests/Import/GenericJsonlImporterTest.php
git commit -m "test: cover Station-shaped JSONL imports"
```

---

## Task 6.5: Documentation and release metadata

**Why:** Consumers need a clear recipe for subclassing the importer and choosing source/content identity.

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `VERSION`

- [ ] **Step 1: README**

Add a JSONL import section covering:

- Migration/table purpose.
- Required subclass hooks.
- `EloquentImportMarkerTrait` usage.
- Importer namespace/source identity rule.
- Content hash rule.
- Rerun/idempotency semantics.
- Fail-fast vs `continueOnError`.
- Station updater bridge as an example, explicitly noting that Station owns the bridge class.

- [ ] **Step 2: Changelog and version**

Add `v0.4.0-alpha` changelog entry and set `VERSION` to `0.4.0-alpha`.

- [ ] **Step 3: Full release gate**

```
composer validate --strict
vendor/bin/phpunit --colors=never --do-not-cache-result
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

- [ ] **Step 4: Commit**

```
git add README.md CHANGELOG.md VERSION
git commit -m "docs: document JSONL importer"
```

---

## Done criteria

- `attest_import_markers` is created by package migrations.
- `GenericJsonlImporter` can lazily import JSONL from a path or iterable.
- Consumer subclasses control parse, chain, type, payload, content hash, subject, correlation, and tenant.
- `EloquentImportMarkerTrait` implements marker reads/writes with duplicate-safe inserts.
- Reruns and duplicate records do not emit duplicate envelopes.
- Failed records do not get markers.
- Marker insertion and envelope append are transactionally coupled for `EloquentChainStore`.
- Station-shaped updater audit rows are covered by adapter tests without adding Station dependencies.
- README documents source identity and record identity clearly enough for consumers to avoid marker collisions.
- Full local release gate passes.

## Commit boundaries

1. `feat: add attest import marker schema`
2. `feat: add JSONL import result types`
3. `feat: add Eloquent import marker trait`
4. `feat: add generic JSONL importer`
5. `test: cover Station-shaped JSONL imports`
6. `docs: document JSONL importer`

Do not tag until the full release gate passes and `main` is aligned with `origin/main`.
