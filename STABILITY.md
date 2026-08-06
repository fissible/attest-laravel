# Stability and Versioning

`fissible/attest-laravel` is the Laravel adapter for [`fissible/attest`](https://github.com/fissible/attest).
This document defines what "stable" will cover from **v1.0.0** onward, following
[semantic versioning](https://semver.org/).

> **Status: binding as of `1.0.0`.** The surface below is the supported `1.x` contract, and the
> `@api` / `@internal` docblock annotations in source match it class for class. Where the two ever
> disagree, that is a bug in one of them — report it.

This adapter sits on top of core's own guarantees — see
[`fissible/attest`'s `STABILITY.md`](https://github.com/fissible/attest/blob/main/STABILITY.md)
for the underlying PHP API, wire formats, and CLI JSON schemas. Where this adapter re-exposes a
core type (e.g. `EvidenceChain`, `SignedEnvelope`, `ChainStore`), core's stability applies; this
document covers the **Laravel-specific** surface the adapter adds.

## `@api` — the supported surface

Classes, interfaces, enums, and traits listed below, together with their public methods and
properties, are the supported surface. Within the `1.x` line:

- No breaking changes to their signatures or documented behavior.
- Additions are additive (new optional parameters, new methods) and will not break existing
  callers.

Breaking changes to the `@api` surface require a major version bump.

## `@internal` — implementation detail

Anything not listed below — every command class, service, resolver, locking strategy, and support
helper not named here — is implementation detail. It may change or be removed in any release,
including a patch. Do not depend on it. Drive the package through the facade, the container
bindings, the Artisan commands, and the import primitives described here.

## The `@api` surface

**Entry point** — `Facades\Attest` (the `chain()`, `store()`, `claimStore()` static methods) and
its accessor `Support\AttestRegistry` (same three methods). `Attest::chain($id)` returns a fresh
core `EvidenceChain` on every call.

**Storage** — `Stores\EloquentChainStore` (implements core `ChainStore` + `RawChainStore`) and
`Stores\EloquentAnchorClaimStore` (implements core `AnchorClaimStore`). These are resolved from
the container via the core contracts; consumers depend on the **core interfaces**, not these
concrete classes, except when binding a replacement.

**Locking extension point** — `Stores\Locking\ChainLocker` (the interface). A consumer adding a
new database driver implements this. The shipped concrete strategies (`SqliteChainLocker`,
`MysqlChainLocker`, `PostgresChainLocker`) are `@internal` — their selection is automatic.

**Read-side models** — `Models\AttestEnvelope`, `Models\AttestAnchorClaim`. Query-only
convenience over the tables; the store owns all writes. Documented `@property` columns are stable
(additive only).

On `AttestEnvelope`, the query scopes `forCorrelation()`, `forSubject()`, `forTenant()` and the
`signed()` decoder are stable surface, including their documented ordering: `forCorrelation()`
and `forSubject()` return oldest-first by `created_at` with `envelope_id` as tiebreaker, and are
not chain-scoped. Consumers rely on that cross-chain behavior to answer a correlation lookup
without knowing how chains are sharded, so narrowing it would be a breaking change.

**Events** — `Events\EnvelopeRecorded` (public `string $chainId`, core `SignedEnvelope $signed`),
dispatched after the append transaction commits.

**Queue** — `Jobs\AnchorPendingBatch` (constructor signature: `$chainId`, `$fromSeq`, `$toSeq`,
`$driver`, `$calendarUrls`, `$minCalendars`). Serialized into queue payloads, so its constructor
shape is a compatibility surface.

**Import primitives** — `Import\GenericJsonlImporter` (the abstract base and its documented
`protected` extension hooks: `importer()`, `parseLine()`, `buildPayload()`, `chainIdFor()`,
`contentHashFor()`, `importMarkerConnection()`), `Import\EloquentImportMarkerTrait`,
`Import\JsonlImportContext`, `Import\JsonlImportOptions`, `Import\JsonlImportResult`,
`Import\JsonlImportFailure`, `Import\JsonlImportException`.

`Import\AlreadyImported` is **not** part of this surface, despite having been listed here while the
contract was provisional. It is control flow internal to `GenericJsonlImporter`: the base throws it
inside the append callback to roll back a concurrent duplicate, and catches it itself. A consumer
never sees one, so freezing it would have frozen an implementation detail. It is annotated
`@internal` in source.

**Service registration** — `AttestServiceProvider`. The container bindings it registers (the core
`ChainStore`, `RawChainStore`, `AnchorClaimStore`, `Signer`, `ChainLocker`, and `AttestRegistry`)
are the contract; the wiring details are not.

## Artisan / CLI contract

The Artisan commands — their names, options, exit codes, and `--json` output schemas — are stable
surface, documented in the README:

- `attest:verify`, `attest:bundle:export`, `attest:bundle:verify`, `attest:integrity:audit`
- `attest:anchor`, `attest:upgrade` (anchoring — see Experimental below)

Exit codes: `0` clean, `1` invalid options, `4` detected drift (and the anchoring/verify codes
documented per command). The `--json` payloads conform to core's frozen `attest.cli.*.v1`
schemas. The PHP classes under `src/Console/Commands/` that implement these commands are
`@internal`.

## Database schema contract

The published migrations create three tables whose column shapes are frozen within `1.x`
(additions are additive; removals or renames of existing columns require a major bump):

- `attest_envelopes` — the canonical write store (raw signed envelope + read-side index columns).
- `attest_anchor_claims` — anchor-claim coordination (experimental; see below).
- `attest_import_markers` — idempotency markers for JSONL import.

The **raw canonical envelope bytes** stored in `attest_envelopes` are governed by core's wire
format, not this adapter — signatures are computed over them, so they cannot change within `1.x`.
The read-side index columns are a convenience projection and are audited against the raw envelope
by `attest:integrity:audit`; they are never verifier trust input.

That projection includes `correlation`, `subject`, and `tenant`, mirroring the optional envelope
fields of the same names. Their presence and semantics are stable within `1.x`, as is the
guarantee that `attest:integrity:audit` compares them against the raw envelope. Note what the
projection does and does not protect: editing one cannot forge evidence or make a broken chain
verify, because `attest:verify` reads only `raw_envelope` — but blanking one *can* hide a row
from the query scopes while the chain still verifies clean. The audit detects that; it is not
prevented. Applications that need completeness guarantees rather than query latency should read
the chain.

## Configuration contract

The published `attest` config keys and the `ATTEST_*` environment variable names documented in the
README (`ATTEST_CONNECTION`, `ATTEST_SIGNING_KEY_SEED`, `ATTEST_SIGNING_KEY_ID`,
`ATTEST_DEFAULT_CHAIN`, `ATTEST_DEFAULT_DRIVER`, `ATTEST_ANCHOR_QUEUE`, `ATTEST_MIN_ANCHOR`) are
stable surface within `1.x`. New keys may be added with safe defaults.

## Framework support

`1.x` supports **Laravel 12 and 13** on PHP `^8.2` (Laravel 13 requires PHP `^8.3`; PHP 8.2 is
supported only on Laravel 12). Dropping a still-supported Laravel major within `1.x` would be a
breaking change. Adding support for a newer Laravel major is additive.

## Experimental: anchoring

The anchoring workflow wraps core's **`@experimental`** anchoring subsystem (OpenTimestamps
submission and Bitcoin header verification). In `1.x` the following follow core's experimental
status — usable and tested, but their behavior and shape may change in a minor release until core
graduates anchoring after live-network validation:

- The `attest:anchor` and `attest:upgrade` commands' anchoring *behavior* (the command names,
  options, and exit codes remain stable surface; the network-dependent outcomes do not).
- `Jobs\AnchorPendingBatch`'s anchoring semantics (its constructor shape is still a stable
  queue-payload surface, but what a run produces depends on the experimental subsystem).
- `Stores\EloquentAnchorClaimStore` and `Models\AttestAnchorClaim` / the `attest_anchor_claims`
  table.

Note the asymmetry inherited from core: the **claim-store contract** is stable — both
`Testing\ChainStoreContractTests` and `Testing\AnchorClaimStoreContractTests` are core `@api` test
support, so an adapter store that satisfies them will keep doing so within `1.x`. What remains
experimental is the live anchoring *workflow* those stores coordinate, not the storage contract
itself.
