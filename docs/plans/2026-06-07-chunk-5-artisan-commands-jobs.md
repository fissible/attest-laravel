# fissible/attest-laravel - Chunk 5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Laravel-native operational surfaces on top of the Chunk 4 Eloquent foundation: Artisan commands for anchor, upgrade, verify, bundle export, and bundle verify; an `AnchorPendingBatch` queue job; command-ready scheduling docs; and an Eloquent index-audit command for the read-side metadata columns.

**Architecture:** Do not spawn `bin/attest` as a subprocess. Commands and jobs resolve the core `ChainStore`, `AnchorClaimStore`, `Signer`, drivers, header providers, and verifier from Laravel services, then call core APIs directly. This keeps storage Eloquent-backed, preserves Laravel transaction/queue ergonomics, and lets tests run with injected mock drivers/clients instead of shelling out.

**Tech Stack:** PHP 8.2+, Laravel 11/12 (`illuminate/support`, `illuminate/database`, `illuminate/console`, `illuminate/bus`, `illuminate/queue`), `orchestra/testbench`, PHPUnit 11, PHPStan 1.x. SQLite/MySQL/Postgres matrix from Chunk 4 stays unchanged.

**Spec:** Core spec `~/lib/fissible/station/docs/superpowers/specs/2026-05-25-fissible-attest-design.md` section 14; Chunk 4 spec `docs/specs/2026-06-06-chunk-4-eloquent-foundation.md`.

**Tag at completion:** `v0.3.0-alpha`.

---

## Assumed from prior chunks

From `fissible/attest-laravel` Chunk 4:

- `AttestServiceProvider` binds `ChainStore`, `AnchorClaimStore`, `Signer`, `AttestRegistry`, and `ChainLocker`.
- `EloquentChainStore` implements `ChainStore` and `RawChainStore`.
- `EloquentAnchorClaimStore` implements `AnchorClaimStore`.
- `config/attest.php` has `connection`, lock settings, `signing_key`, and `claim_ttl_seconds`.
- `EnvelopeRecorded` fires after successful append commits.

From `fissible/attest` core:

- Anchor primitives: `AnchorService`, `NullDriver`, `OpenTimestampsDriver`, `OpenTimestampsCalendarClient`, `AnchorSetResolver`, `AnchorEnvelope`, `ProofState`.
- Verification primitives: `Verifier`, `VerificationPolicy`, `SignatureVerifier`, `TrustedKey`, `HeaderProviderSet`.
- Bundle primitives: `BundleExporter`, `BundleReader`, `BundleStore`.
- CLI support classes may be referenced for behavior parity, but this adapter should not depend on Symfony command classes or execute them.

Core `v0.4.2-alpha` is the preferred dependency once tagged because it contains the latest CLI/OTS fixes. Chunk 5 implementation should still use stable core value APIs and not rely on core command test seams.

---

## Command names and behavior contract

Laravel command names:

```
attest:anchor
attest:upgrade
attest:verify
attest:bundle:export
attest:bundle:verify
attest:integrity:audit
```

Exit codes mirror core CLI for verification commands:

- `0` - verified/success.
- `1` - command/config error.
- `2` - `INTEGRITY_VERIFIED_UNTRUSTED` without `--allow-untrusted`.
- `3` - `ANCHOR_BELOW_MIN`.
- `4` - invalid chain/signature/anchor, bundle open error, or anchor submission failure.
- `5` - provider disagreement.

Non-verification operational commands use:

- `0` - completed or no-op.
- `1` - command/config error.
- `4` - core operation failed after valid options, such as calendar unavailable or invalid bundle export policy.

JSON output should match core CLI schemas where possible:

- `attest.cli.anchor.v1`
- `attest.cli.upgrade.v1`
- `attest.cli.result.v1`
- `attest.cli.export.v1`
- `attest.laravel.integrity-audit.v1` for the adapter-only audit command.

---

## File Structure

### New files

```
src/Support/AnchorDriverResolver.php                       (Task 5.1)
src/Support/HeaderProviderResolver.php                     (Task 5.2)
src/Support/TrustedKeyResolver.php                         (Task 5.3)
src/Support/VerificationExitCode.php                       (Task 5.4)
src/Support/CommandJson.php                                (Task 5.4)
src/Services/AnchorRangeRunner.php                         (Task 5.5)
src/Services/UpgradePendingAnchors.php                     (Task 5.6)
src/Services/VerifyChain.php                               (Task 5.7)
src/Services/BundleOperations.php                          (Task 5.8)
src/Services/IntegrityAudit.php                            (Task 5.9)
src/Jobs/AnchorPendingBatch.php                            (Task 5.10)
src/Console/Commands/AnchorCommand.php                     (Task 5.11)
src/Console/Commands/UpgradeCommand.php                    (Task 5.12)
src/Console/Commands/VerifyCommand.php                     (Task 5.13)
src/Console/Commands/BundleExportCommand.php               (Task 5.14)
src/Console/Commands/BundleVerifyCommand.php               (Task 5.14)
src/Console/Commands/IntegrityAuditCommand.php             (Task 5.15)
tests/Support/AnchorDriverResolverTest.php                 (Task 5.1)
tests/Support/HeaderProviderResolverTest.php               (Task 5.2)
tests/Support/TrustedKeyResolverTest.php                   (Task 5.3)
tests/Services/AnchorRangeRunnerTest.php                   (Task 5.5)
tests/Services/UpgradePendingAnchorsTest.php               (Task 5.6)
tests/Services/VerifyChainTest.php                         (Task 5.7)
tests/Services/BundleOperationsTest.php                    (Task 5.8)
tests/Services/IntegrityAuditTest.php                      (Task 5.9)
tests/Jobs/AnchorPendingBatchTest.php                      (Task 5.10)
tests/Console/AnchorCommandTest.php                        (Task 5.11)
tests/Console/UpgradeCommandTest.php                       (Task 5.12)
tests/Console/VerifyCommandTest.php                        (Task 5.13)
tests/Console/BundleCommandTest.php                        (Task 5.14)
tests/Console/IntegrityAuditCommandTest.php                (Task 5.15)
```

### Modified files

```
composer.json                                              (Task 5.0)
config/attest.php                                          (Task 5.0)
src/AttestServiceProvider.php                              (Task 5.0, 5.11-5.15)
README.md                                                  (Task 5.16)
CHANGELOG.md                                               (Task 5.16)
VERSION                                                    (Task 5.16)
.github/workflows/ci.yml                                   (Task 5.17, only if new dependencies need services/cache updates)
```

---

## Task 5.0: Dependencies, config, and service-provider registration points

**Why:** Chunk 5 introduces queue jobs, command bindings, optional OTS HTTP clients, trusted-key/header-provider config, and defaults for anchor automation. Put the config shape in place before adding services and commands.

**Files:**
- Modify: `composer.json`
- Modify: `config/attest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Add Laravel queue/bus dependencies**

Add to `require`:

```json
"illuminate/bus": "^11.0 || ^12.0",
"illuminate/queue": "^11.0 || ^12.0"
```

Add to `require-dev` for mocked OTS/header HTTP tests:

```json
"guzzlehttp/guzzle": "^7.7",
"guzzlehttp/psr7": "^2.6"
```

Keep Guzzle out of `require`; production OTS/header commands fail fast with a clear message if the optional HTTP stack is absent.

- [ ] **Step 2: Add Composer suggest entries**

Add:

```json
"suggest": {
  "guzzlehttp/guzzle": "PSR-18 HTTP client used by OpenTimestamps calendars and Bitcoin header provider commands.",
  "guzzlehttp/psr7": "PSR-7 factories used by OpenTimestamps calendars and Bitcoin header provider commands."
}
```

Do not make these hard production dependencies.

- [ ] **Step 3: Bump the local path override when core v0.4.2-alpha exists**

In `composer.json` path repository options:

```json
"versions": {
  "fissible/attest": "0.4.2-alpha"
}
```

If core `v0.4.2-alpha` has not been tagged yet, do not block the plan. Leave the override at `0.4.1-alpha` during implementation and update in Task 5.16 before release.

- [ ] **Step 4: Extend `config/attest.php`**

Append:

```php
'anchoring' => [
    'default_driver' => env('ATTEST_DEFAULT_DRIVER', 'local-only'),
    'default_chain' => env('ATTEST_DEFAULT_CHAIN'),
    'calendars' => array_filter(array_map('trim', explode(',', env('ATTEST_OTS_CALENDARS', '')))),
    'min_calendars' => (int) env('ATTEST_OTS_MIN_CALENDARS', 1),
    'queue' => env('ATTEST_ANCHOR_QUEUE'),
    'connection' => env('ATTEST_ANCHOR_QUEUE_CONNECTION'),
],

'verification' => [
    'min_anchor_outcome' => env('ATTEST_MIN_ANCHOR'),
    'require_trusted_key' => env('ATTEST_REQUIRE_TRUSTED_KEY', true),
    'trusted_keys' => [],
    'trusted_key_files' => [],
    'allow_provider_disagreement' => false,
],

'headers' => [
    'bitcoin_core_rpc' => env('ATTEST_BITCOIN_CORE_RPC'),
    'bitcoin_core_cookie' => env('ATTEST_BITCOIN_CORE_COOKIE'),
    'esplora_url' => env('ATTEST_ESPLORA_URL'),
],
```

`trusted_keys` entries are strings in `<key_id>=<base64-pubkey>` form. Do not auto-trust keys embedded in bundles.

- [ ] **Step 5: Add service-provider placeholders**

In `AttestServiceProvider::register()`, bind the support/services as singletons or scoped services once their classes exist:

- `AnchorDriverResolver`
- `HeaderProviderResolver`
- `TrustedKeyResolver`
- `AnchorRangeRunner`
- `UpgradePendingAnchors`
- `VerifyChain`
- `BundleOperations`
- `IntegrityAudit`

In `boot()`, register commands only when running in console:

```php
if ($this->app->runningInConsole()) {
    $this->commands([
        \Fissible\AttestLaravel\Console\Commands\AnchorCommand::class,
        // ...
    ]);
}
```

Do not auto-register scheduled tasks. Consumers wire scheduling explicitly in their app.

- [ ] **Step 6: Verify**

Run:

```
composer update
composer validate --strict
vendor/bin/phpunit --filter AttestServiceProviderTest
```

Commit:

```
git add composer.json composer.lock config/attest.php src/AttestServiceProvider.php
git commit -m "chore: prepare adapter command dependencies"
```

---

## Task 5.1: Anchor driver resolver

**Why:** Commands and jobs need one place that maps configured driver names to core `AnchorDriver` instances. It must be testable without network and must fail clearly when OTS needs Guzzle.

**Files:**
- Create: `src/Support/AnchorDriverResolver.php`
- Create: `tests/Support/AnchorDriverResolverTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Define resolver**

`AnchorDriverResolver` constructor takes Laravel config repository and an optional callable test seam:

```php
/**
 * @param (callable(): OpenTimestampsCalendarClient)|null $calendarClientFactory
 */
public function __construct(ConfigRepository $config, ?callable $calendarClientFactory = null) { ... }
```

Methods:

```php
public function resolve(?string $driverName = null, array $calendarUrls = [], ?int $minCalendars = null): AnchorDriver;

/** @return list<AnchorDriver> */
public function verificationDrivers(): array;
```

Behavior:

- `local-only` -> `NullDriver`.
- `opentimestamps` -> `OpenTimestampsDriver`.
- Unknown driver -> `InvalidArgumentException`.
- OTS calendar URLs default to config `attest.anchoring.calendars`; if still empty, use core driver defaults.
- OTS min calendars defaults to config `attest.anchoring.min_calendars`.
- For OTS upgrade, the allowlist is the same calendar URL list when explicitly provided.
- If Guzzle/PSR-7 classes are missing and no factory seam is injected, throw `RuntimeException` with an install hint.

- [ ] **Step 2: Tests**

Cover:

- Resolves `local-only`.
- Resolves `opentimestamps` with a mocked `OpenTimestampsCalendarClient`.
- Rejects unknown driver.
- `verificationDrivers()` always includes local-only and includes OTS only when the optional HTTP stack or injected seam is available.
- Explicit calendar URLs override config.

- [ ] **Step 3: Bind in provider**

Bind as singleton. Tests may override binding with a fake resolver.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter AnchorDriverResolverTest
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
git add src/Support/AnchorDriverResolver.php tests/Support/AnchorDriverResolverTest.php src/AttestServiceProvider.php
git commit -m "feat: resolve anchor drivers from Laravel config"
```

---

## Task 5.2: Header provider resolver

**Why:** `attest:verify` and `attest:bundle:verify` need configurable Bitcoin header providers without importing CLI command code.

**Files:**
- Create: `src/Support/HeaderProviderResolver.php`
- Create: `tests/Support/HeaderProviderResolverTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Define resolver**

Methods:

```php
public function resolve(?string $bitcoinCoreRpc = null, ?string $bitcoinCoreCookie = null, ?string $esploraUrl = null): HeaderProviderSet;
```

Defaults come from:

- `attest.headers.bitcoin_core_rpc`
- `attest.headers.bitcoin_core_cookie`
- `attest.headers.esplora_url`

Behavior mirrors core `HeaderProviderFactory`: Bitcoin Core provider is local trust, Esplora provider is remote trust. If a provider URL is set but Guzzle is absent, throw a clear `RuntimeException`.

- [ ] **Step 2: Tests**

Use mocked HTTP stack availability where possible. Tests do not need live Bitcoin responses; provider behavior is covered in core. Adapter tests assert resolver composition and failure messages.

- [ ] **Step 3: Verify and commit**

```
vendor/bin/phpunit --filter HeaderProviderResolverTest
git add src/Support/HeaderProviderResolver.php tests/Support/HeaderProviderResolverTest.php src/AttestServiceProvider.php
git commit -m "feat: resolve verification header providers"
```

---

## Task 5.3: Trusted key resolver

**Why:** Verification commands need trusted keys from CLI options, config, and files. This must preserve the bundle trust model: claimed keys are never automatically trusted.

**Files:**
- Create: `src/Support/TrustedKeyResolver.php`
- Create: `tests/Support/TrustedKeyResolverTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Define resolver**

Method:

```php
/**
 * @param list<string> $inline
 * @param list<string> $files
 * @return list<TrustedKey>
 */
public function resolve(array $inline = [], array $files = []): array;
```

It merges:

1. Config `attest.verification.trusted_keys`.
2. Config `attest.verification.trusted_key_files`.
3. Inline command options.
4. Command file options.

Inline format: `<key_id>=<base64-pubkey>`. File format: base64 32-byte pubkey; key id is not inferred unless the file option uses `<key_id>=<path>`. Support both:

- `/path/to/key.pub` -> trusted key with fingerprint only.
- `issuer=/path/to/key.pub` -> trusted key with key id `issuer`.

- [ ] **Step 2: Tests**

Cover valid inline, valid file, config + options merge, invalid base64, wrong pubkey length, missing file, and explicit key id from file option.

- [ ] **Step 3: Verify and commit**

```
vendor/bin/phpunit --filter TrustedKeyResolverTest
git add src/Support/TrustedKeyResolver.php tests/Support/TrustedKeyResolverTest.php src/AttestServiceProvider.php
git commit -m "feat: resolve trusted verification keys"
```

---

## Task 5.4: Shared command output and exit helpers

**Why:** Artisan commands should keep the core CLI JSON schemas and exit-code mapping without duplicating match expressions in every command.

**Files:**
- Create: `src/Support/VerificationExitCode.php`
- Create: `src/Support/CommandJson.php`
- Add tests as needed under `tests/Support/`

- [ ] **Step 1: `VerificationExitCode`**

Static method:

```php
public static function forOutcome(VerificationOutcome $outcome, bool $allowUntrusted = false): int;
```

Mapping matches core spec section 13.

- [ ] **Step 2: `CommandJson`**

Static helpers:

- `verification(string $command, VerificationResult $result, int $exitCode): array`
- `warningList(array $warnings): array`
- `print(OutputStyle $output, array $payload): void`

Use the same fields as core `attest.cli.result.v1`.

- [ ] **Step 3: Verify and commit**

```
vendor/bin/phpunit --filter Support
git add src/Support/VerificationExitCode.php src/Support/CommandJson.php tests/Support
git commit -m "feat: share artisan command result formatting"
```

---

## Task 5.5: `AnchorRangeRunner` service

**Why:** `attest:anchor` and `AnchorPendingBatch` need the same anchor execution behavior: resolve driver, call core `AnchorService`, preserve warnings, and classify anchored vs reconciled/skipped outcomes.

**Files:**
- Create: `src/Services/AnchorRangeRunner.php`
- Create: `tests/Services/AnchorRangeRunnerTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Define result value object**

Use a small readonly class nested in the namespace, not an array:

```php
final readonly class AnchorRunResult
{
    public function __construct(
        public string $result, // anchored|reconciled|skipped
        public ?string $anchorId,
        public ?string $envelopeId,
        public string $driver,
        public string $state,
        public string $chainId,
        public int $fromSeq,
        public int $toSeq,
        public array $warnings = [],
    ) {}
}
```

- [ ] **Step 2: Implement runner**

Constructor:

- `ChainStore`
- `AnchorClaimStore`
- `Signer`
- `AnchorDriverResolver`
- optional `claimedBy` string

Method:

```php
public function anchorRange(string $chainId, int $fromSeq, int $toSeq, ?string $driverName = null, array $calendarUrls = [], ?int $minCalendars = null): AnchorRunResult;
```

Use core `AnchorService::anchorRange()`.

Outcome classification:

- New envelope appended -> `anchored`.
- Existing envelope returned by reconciliation -> `reconciled`.
- `null` from claim contention with no existing envelope -> `skipped`.

Record the tail sequence before calling `AnchorService::anchorRange()`. If the returned envelope's sequence is greater than the prior tail sequence, classify it as `anchored`; otherwise classify it as `reconciled`.

If classifying `anchor_id` is needed for skipped, recompute the target from raw bytes and `AnchorId::derive()`. Use `RawChainStore` path because Eloquent store has it.

- [ ] **Step 3: Tests**

Cover:

- Local-only anchor appends a receipt envelope.
- Existing completed anchor reconciles.
- Claim held with no existing envelope yields `skipped`.
- Missing range throws.
- OTS path works with mocked calendar client via resolver seam.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter AnchorRangeRunnerTest
git add src/Services/AnchorRangeRunner.php tests/Services/AnchorRangeRunnerTest.php src/AttestServiceProvider.php
git commit -m "feat: add anchor range runner service"
```

---

## Task 5.6: `UpgradePendingAnchors` service

**Why:** Core has upgrade logic in the CLI command, but the Laravel adapter should expose reusable service behavior for both commands and future scheduled jobs.

**Files:**
- Create: `src/Services/UpgradePendingAnchors.php`
- Create: `tests/Services/UpgradePendingAnchorsTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Define result value objects**

Create:

- `UpgradeRunResult`
- `UpgradedAnchor`
- `UnchangedAnchor`
- `FailedAnchor`

Keep fields aligned with core JSON:

- `anchor_id`
- `previous_envelope_id`
- `new_envelope_id`
- `envelope_id`
- `state`
- `error`

- [ ] **Step 2: Implement service**

Constructor:

- `ChainStore`
- `Signer`
- `AnchorDriverResolver`

Methods:

```php
public function upgradeOne(string $chainId, string $anchorId, array $calendarUrls = []): UpgradeRunResult;

public function upgradeAllPending(string $chainId, array $calendarUrls = []): UpgradeRunResult;
```

Algorithm:

1. Read all chain envelopes.
2. Resolve groups with core `AnchorSetResolver`.
3. Consider only valid OTS groups.
4. For `upgradeOne`, if the matching group is already non-pending, return unchanged instead of failure.
5. For pending receipts, call `OpenTimestampsDriver::upgrade()`.
6. If state advances to `UPGRADED`, append `attest.anchor.upgraded` through `EvidenceChain`, with `supersedes_envelope_id`.
7. If state stays pending, return unchanged.
8. In all-pending mode, `CalendarUnavailable` is best effort: collect failure/unchanged according to core behavior and continue.
9. In single-anchor mode, `CalendarUnavailable` returns failure and maps to exit 4 in the command.

- [ ] **Step 3: Tests**

Port intent from core CLI tests:

- Single anchor upgrades to new envelope with `supersedes_envelope_id`.
- Already upgraded is idempotent unchanged.
- All-pending continues when one calendar is unavailable.
- No pending anchors is exit-success no-op.
- Nonexistent anchor id is failure.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter UpgradePendingAnchorsTest
git add src/Services/UpgradePendingAnchors.php tests/Services/UpgradePendingAnchorsTest.php src/AttestServiceProvider.php
git commit -m "feat: add pending anchor upgrade service"
```

---

## Task 5.7: `VerifyChain` service

**Why:** Verification commands need a Laravel-native wrapper around core `Verifier` using Eloquent storage, configured trusted keys, configured header providers, and policy defaults.

**Files:**
- Create: `src/Services/VerifyChain.php`
- Create: `tests/Services/VerifyChainTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Implement service**

Method:

```php
public function verify(
    string $chainId,
    int $fromSeq = 1,
    ?int $toSeq = null,
    ?string $minAnchor = null,
    array $trustedKeys = [],
    array $trustedKeyFiles = [],
    bool $allowUntrusted = false,
    bool $allowProviderDisagreement = false,
    ?string $bitcoinCoreRpc = null,
    ?string $bitcoinCoreCookie = null,
    ?string $esploraUrl = null,
): VerificationResult;
```

Use:

- `TrustedKeyResolver`
- `HeaderProviderResolver`
- `AnchorDriverResolver::verificationDrivers()`
- core `VerificationPolicy`
- core `Verifier`

Parse `minAnchor` with adapter code equivalent to core `MinAnchorOption`. Do not import the Symfony command.

- [ ] **Step 2: Tests**

Cover:

- Trusted local chain verifies.
- Missing trusted keys returns untrusted outcome.
- `allowUntrusted` policy changes exit only at command layer, not result outcome.
- Local-only anchor satisfies `local_only`.
- Higher min anchor fails local-only.
- Provider disagreement can be exercised with fake header providers through resolver override.

- [ ] **Step 3: Verify and commit**

```
vendor/bin/phpunit --filter VerifyChainTest
git add src/Services/VerifyChain.php tests/Services/VerifyChainTest.php src/AttestServiceProvider.php
git commit -m "feat: add chain verification service"
```

---

## Task 5.8: Bundle operations service

**Why:** Bundle commands should use core bundle APIs while resolving Laravel trusted keys/header providers for verification.

**Files:**
- Create: `src/Services/BundleOperations.php`
- Create: `tests/Services/BundleOperationsTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Export method**

```php
public function export(
    string $chainId,
    int $fromSeq,
    int $toSeq,
    string $outPath,
    ?string $note = null,
    ?string $issuerHint = null,
    array $claimedKeyFiles = [],
): BundleExportResult;
```

Use core `BundleExporter::create($store)`. Claimed key files are base64 public-key files. As in core CLI, filename without extension may be used as informational key id.

- [ ] **Step 2: Verify method**

```php
public function verify(
    string $bundlePath,
    ?string $chainId = null,
    ?string $minAnchor = null,
    array $trustedKeys = [],
    array $trustedKeyFiles = [],
    bool $allowUntrusted = false,
    bool $allowProviderDisagreement = false,
    ?string $bitcoinCoreRpc = null,
    ?string $bitcoinCoreCookie = null,
    ?string $esploraUrl = null,
): BundleVerifyResult;
```

Open `BundleReader`, choose explicit chain or first manifest chain, collect proof envelopes, construct `BundleStore`, then core `Verifier` with detached proof envelopes.

Important: claimed keys in bundles are never trusted automatically.

- [ ] **Step 3: Tests**

Cover:

- Export writes a bundle.
- Export refuses wider-only anchor.
- Export emits pending-anchor warning.
- Verify local-only anchored bundle succeeds.
- Claimed keys alone yield untrusted.
- Invalid proof envelope signature drops anchor group.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter BundleOperationsTest
git add src/Services/BundleOperations.php tests/Services/BundleOperationsTest.php src/AttestServiceProvider.php
git commit -m "feat: add bundle export and verify services"
```

---

## Task 5.9: Integrity audit service for Eloquent index columns

**Why:** Chunk 4 deferred INDEX_DRIFT because core verifier has no Eloquent index extension point. The adapter can still audit its own read-side metadata by comparing indexed columns to decoded raw envelope bytes.

**Files:**
- Create: `src/Services/IntegrityAudit.php`
- Create: `tests/Services/IntegrityAuditTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Define audit result**

Fields:

- `chain_id`
- `from_seq`
- `to_seq`
- `checked_count`
- `drifts`

Each drift:

- `sequence`
- `column`
- `stored`
- `computed`

- [ ] **Step 2: Implement audit**

Read rows from `attest_envelopes` for the chain/range. For each row:

1. Decode `raw_envelope` with core `EnvelopeCodec::decodeSigned()`.
2. Compare row `sequence` to envelope `seq`.
3. Compare row `envelope_id` to envelope id.
4. Compare row `prev_hash`, `self_hash`, `key_id`, and `type`.
5. Compare row `created_at` to envelope timestamp using `Timestamp` normalization.

Do not mutate data in this chunk. This is read-only reporting.

- [ ] **Step 3: Tests**

Cover no drift and one drift per important column by raw SQL mutation.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter IntegrityAuditTest
git add src/Services/IntegrityAudit.php tests/Services/IntegrityAuditTest.php src/AttestServiceProvider.php
git commit -m "feat: audit eloquent attest index drift"
```

---

## Task 5.10: `AnchorPendingBatch` queue job

**Why:** This is the schedulable/queueable primitive consumers use for periodic anchoring. It must be idempotent under retry and claim contention.

**Files:**
- Create: `src/Jobs/AnchorPendingBatch.php`
- Create: `tests/Jobs/AnchorPendingBatchTest.php`

- [ ] **Step 1: Define job**

```php
final class AnchorPendingBatch implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $chainId,
        public readonly int $fromSeq = 1,
        public readonly ?int $toSeq = null,
        public readonly ?string $driver = null,
        public readonly array $calendarUrls = [],
        public readonly ?int $minCalendars = null,
    ) {}

    public function handle(AnchorRangeRunner $runner, ChainStore $store): void { ... }
}
```

If `toSeq` is null, select the current tail sequence at handle time. If no envelopes exist, no-op.

Do not serialize service instances, signers, stores, or clients.

- [ ] **Step 2: Queue metadata**

If config `attest.anchoring.queue` or `attest.anchoring.connection` is set, the command that dispatches the job should apply it. The job constructor stays storage-agnostic.

- [ ] **Step 3: Tests**

Cover:

- Job anchors current tail.
- Retrying job after first success reconciles/skips without duplicate anchor.
- Empty chain no-ops.
- Job serializes and unserializes.
- OTS mocked driver path can be injected through bound resolver.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter AnchorPendingBatchTest
git add src/Jobs/AnchorPendingBatch.php tests/Jobs/AnchorPendingBatchTest.php
git commit -m "feat: add anchor pending batch job"
```

---

## Task 5.11: `attest:anchor` Artisan command

**Why:** Operators need a Laravel command to anchor a chain range or dispatch the queue job.

**Files:**
- Create: `src/Console/Commands/AnchorCommand.php`
- Create: `tests/Console/AnchorCommandTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Command signature**

```php
protected $signature = 'attest:anchor
    {--chain= : Chain ID}
    {--from=1 : First sequence number}
    {--to= : Last sequence number. Defaults to current tail when dispatching a job.}
    {--driver= : Anchor driver, local-only or opentimestamps}
    {--calendar-url=* : OpenTimestamps calendar URL}
    {--min-calendars= : Minimum calendars required for OTS}
    {--sync : Run immediately instead of dispatching AnchorPendingBatch}
    {--queue= : Queue name override}
    {--connection= : Queue connection override}
    {--json : Emit JSON}';
```

`--chain` defaults to config `attest.anchoring.default_chain`; if still empty, return exit 1.

- [ ] **Step 2: Behavior**

- `--sync` calls `AnchorRangeRunner`.
- Without `--sync`, dispatch `AnchorPendingBatch`.
- If `--to` is omitted in sync mode, read store tail and anchor from `--from` to tail.
- Empty chain -> exit 0 no-op.
- Calendar unavailable or invalid driver -> exit 4 or 1 respectively.

JSON output:

- Sync: core-like `attest.cli.anchor.v1`.
- Dispatch: `attest.laravel.anchor-dispatch.v1` with job class, chain, range, queue, connection.

- [ ] **Step 3: Tests**

Use `$this->artisan()` direct command tests:

- Sync local-only anchors and emits JSON.
- Dispatch mode pushes `AnchorPendingBatch` with expected scalar properties using `Queue::fake()`.
- Missing chain config exits 1.
- Empty chain no-op.
- OTS calendar unavailable maps to exit 4 in sync mode.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter AnchorCommandTest
git add src/Console/Commands/AnchorCommand.php tests/Console/AnchorCommandTest.php src/AttestServiceProvider.php
git commit -m "feat: add attest anchor artisan command"
```

---

## Task 5.12: `attest:upgrade` Artisan command

**Why:** Operators need to sweep pending OTS receipts from Laravel without shelling out.

**Files:**
- Create: `src/Console/Commands/UpgradeCommand.php`
- Create: `tests/Console/UpgradeCommandTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Signature**

```php
protected $signature = 'attest:upgrade
    {--chain= : Chain ID}
    {--anchor-id= : Upgrade a single anchor id}
    {--all-pending : Sweep all pending OTS anchors}
    {--calendar-url=* : OpenTimestamps calendar URL}
    {--json : Emit JSON}';
```

Exactly one of `--anchor-id` or `--all-pending` is required.

- [ ] **Step 2: Behavior**

Call `UpgradePendingAnchors`. JSON schema mirrors `attest.cli.upgrade.v1`.

Exit:

- 0 when no failures or all-pending best-effort only has collected failures.
- 1 invalid options.
- 4 single-anchor failure.

- [ ] **Step 3: Tests**

Mirror core CLI tests:

- No pending anchors exits 0.
- Missing required mutually exclusive flags exits 1.
- Single pending OTS anchor upgrades.
- Already upgraded idempotent.
- All-pending continues on one unavailable calendar.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter UpgradeCommandTest
git add src/Console/Commands/UpgradeCommand.php tests/Console/UpgradeCommandTest.php src/AttestServiceProvider.php
git commit -m "feat: add attest upgrade artisan command"
```

---

## Task 5.13: `attest:verify` Artisan command

**Why:** Operators need chain verification against Eloquent storage, trusted keys, anchor thresholds, and header providers.

**Files:**
- Create: `src/Console/Commands/VerifyCommand.php`
- Create: `tests/Console/VerifyCommandTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Signature**

```php
protected $signature = 'attest:verify
    {--chain= : Chain ID}
    {--from=1 : First sequence number}
    {--to= : Last sequence number}
    {--trusted-key=* : <key_id>=<base64-pubkey>}
    {--trusted-key-file=* : Path or <key_id>=path to base64 pubkey}
    {--min-anchor= : local_only|pending|upgraded_no_headers|remote_header_confirmed|bitcoin_verified}
    {--allow-provider-disagreement : Allow strongest passing provider outcome}
    {--allow-untrusted : Exit 0 for integrity-verified-untrusted}
    {--bitcoin-core-rpc= : Bitcoin Core RPC URL}
    {--bitcoin-core-cookie= : Bitcoin Core cookie file}
    {--esplora-url= : Esplora base URL}
    {--json : Emit JSON}';
```

- [ ] **Step 2: Behavior**

Call `VerifyChain`, then map exit code via `VerificationExitCode`.

Human output should be concise:

```
chain & signatures: verified (3 envelopes, 3 trusted, 0 untrusted)
anchor: local_only
exit 0
```

- [ ] **Step 3: Tests**

Cover exit codes 0, 1, 2, 3, 4, and 5. Use fake provider resolver for provider disagreement. Do not make live network calls.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter VerifyCommandTest
git add src/Console/Commands/VerifyCommand.php tests/Console/VerifyCommandTest.php src/AttestServiceProvider.php
git commit -m "feat: add attest verify artisan command"
```

---

## Task 5.14: Bundle Artisan commands

**Why:** Bundle operations are operator workflows and should be exposed through Laravel's console.

**Files:**
- Create: `src/Console/Commands/BundleExportCommand.php`
- Create: `src/Console/Commands/BundleVerifyCommand.php`
- Create: `tests/Console/BundleCommandTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Export signature**

```php
protected $signature = 'attest:bundle:export
    {--chain= : Chain ID}
    {--from= : First sequence number}
    {--to= : Last sequence number}
    {--out= : Output bundle path}
    {--note= : Manifest note}
    {--issuer-hint= : Manifest issuer hint}
    {--include-claimed-key=* : Path to base64 pubkey}
    {--json : Emit JSON}';
```

- [ ] **Step 2: Verify signature**

```php
protected $signature = 'attest:bundle:verify
    {--bundle= : Bundle path}
    {--chain= : Chain ID, defaults to first manifest chain}
    {--trusted-key=* : <key_id>=<base64-pubkey>}
    {--trusted-key-file=* : Path or <key_id>=path to base64 pubkey}
    {--min-anchor= : Anchor threshold}
    {--allow-provider-disagreement}
    {--allow-untrusted}
    {--bitcoin-core-rpc=}
    {--bitcoin-core-cookie=}
    {--esplora-url=}
    {--json}';
```

- [ ] **Step 3: Tests**

Cover:

- Export writes bundle.
- Export invalid options exits 1.
- Export wider-only anchor exits 4.
- Verify local-only bundle exits 0.
- Verify claimed-key-only bundle exits 2.
- Verify missing bundle exits 1.
- Verify invalid proof envelope signature exits 3 with warning when min anchor required.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter BundleCommandTest
git add src/Console/Commands/BundleExportCommand.php src/Console/Commands/BundleVerifyCommand.php tests/Console/BundleCommandTest.php src/AttestServiceProvider.php
git commit -m "feat: add attest bundle artisan commands"
```

---

## Task 5.15: `attest:integrity:audit` Artisan command

**Why:** Operators need a simple way to detect Eloquent read-side index drift without treating indexes as verifier trust input.

**Files:**
- Create: `src/Console/Commands/IntegrityAuditCommand.php`
- Create: `tests/Console/IntegrityAuditCommandTest.php`
- Modify: `src/AttestServiceProvider.php`

- [ ] **Step 1: Signature**

```php
protected $signature = 'attest:integrity:audit
    {--chain= : Chain ID}
    {--from=1 : First sequence number}
    {--to= : Last sequence number}
    {--json : Emit JSON}';
```

- [ ] **Step 2: Behavior**

Call `IntegrityAudit`.

Exit:

- 0 no drift.
- 1 invalid options.
- 4 drift detected.

JSON schema `attest.laravel.integrity-audit.v1`.

- [ ] **Step 3: Tests**

Cover no drift, mutated index column drift, missing chain, and JSON shape.

- [ ] **Step 4: Verify and commit**

```
vendor/bin/phpunit --filter IntegrityAuditCommandTest
git add src/Console/Commands/IntegrityAuditCommand.php tests/Console/IntegrityAuditCommandTest.php src/AttestServiceProvider.php
git commit -m "feat: add attest integrity audit command"
```

---

## Task 5.16: Docs, version, and release metadata

**Why:** The package now has operator-facing commands/jobs. README and changelog need concrete examples and the version should move to `0.3.0-alpha`.

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `VERSION`
- Modify: `composer.json` if core version override still needs `0.4.2-alpha`

- [ ] **Step 1: README**

Add sections:

- Install + migrate recap.
- Configure signer env vars.
- Record through facade.
- Anchor immediately:

```
php artisan attest:anchor --chain=tenant:5 --from=1 --to=100 --sync
```

- Schedule anchor job:

```php
// routes/console.php or app/Console/Kernel.php
Schedule::command('attest:anchor --chain=tenant:5')->hourly();
```

Make clear the package does not auto-register schedules.

- Verify:

```
php artisan attest:verify --chain=tenant:5 --trusted-key=prod=...
```

- Bundle export/verify.
- Integrity audit.

- [ ] **Step 2: Changelog**

Add `0.3.0-alpha` entry with:

- Artisan commands.
- `AnchorPendingBatch`.
- Configured resolver services.
- Integrity audit.
- Optional Guzzle behavior.

- [ ] **Step 3: Version**

Set `VERSION` to `0.3.0-alpha`.

- [ ] **Step 4: Verify and commit**

```
composer validate --strict
vendor/bin/phpunit --colors=never
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
git add README.md CHANGELOG.md VERSION composer.json composer.lock
git commit -m "docs: document chunk 5 commands and jobs"
```

---

## Task 5.17: CI and release

**Why:** Chunk 5 touches command/job code and optional HTTP dependencies. The existing matrix should remain green across SQLite/MySQL/Postgres and Laravel 11/12-compatible Testbench cells.

**Files:**
- Modify: `.github/workflows/ci.yml` only if needed.

- [ ] **Step 1: Run local verification**

```
composer validate --strict
vendor/bin/phpunit --colors=never
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

- [ ] **Step 2: Push**

```
git push origin main
gh run watch --repo fissible/attest-laravel --exit-status
```

- [ ] **Step 3: Tag**

Manual alpha tag procedure:

```
git tag -a v0.3.0-alpha -m "v0.3.0-alpha"
git push origin v0.3.0-alpha
gh release view v0.3.0-alpha --repo fissible/attest-laravel --json isPrerelease
```

If the release workflow creates a non-prerelease, mark it prerelease:

```
gh release edit v0.3.0-alpha --repo fissible/attest-laravel --prerelease
```

---

## Acceptance checklist

- [ ] `attest:anchor` can run sync and dispatch `AnchorPendingBatch`.
- [ ] `AnchorPendingBatch` is retry-safe and idempotent via core anchor claims.
- [ ] `attest:upgrade` handles single and all-pending OTS upgrades.
- [ ] `attest:verify` maps core verification outcomes to spec exit codes.
- [ ] `attest:bundle:export` and `attest:bundle:verify` round-trip bundles without trusting claimed keys.
- [ ] `attest:integrity:audit` detects Eloquent index drift.
- [ ] No command shells out to `bin/attest`.
- [ ] No live network calls in tests.
- [ ] SQLite/MySQL/Postgres CI matrix remains green.
- [ ] README documents scheduler usage without package auto-scheduling.
- [ ] Tagged `v0.3.0-alpha` as a prerelease.

---

## Open implementation notes

- Core `v0.4.2-alpha` should be tagged before Chunk 5 release metadata is finalized; otherwise the adapter's path override will lag the core code it was tested against.
- `attest:anchor` intentionally does not discover chains automatically. Consumers must pass `--chain` or configure `attest.anchoring.default_chain`; automatic chain discovery could anchor unintended tenant chains.
- The package does not auto-register schedules. This matches core spec section 14. Scheduler integration means the commands/jobs are scheduler-ready and documented.
- OTS and header-provider commands require optional Guzzle packages. They are `require-dev` for tests and `suggest`/runtime-checked for production.
