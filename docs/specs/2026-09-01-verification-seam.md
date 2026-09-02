# Spec: stable programmatic verification seam (issue #9)

Resolves [#9](https://github.com/fissible/attest-laravel/issues/9), the blocking handoff from
verdict-console ADR 0002 §8. Downstream needs a `@api` entry point that returns a structured
verification result — outcome in the stable vocabulary, verified-through, broken-at, anchor
outcome — while honouring the same configuration resolution `attest:verify` uses.

## Surface

Three new classes under `Fissible\AttestLaravel\Verification`, all annotated `@api` and listed in
`STABILITY.md`. Nothing under `Services\` or `Console\` changes stability status.

### `VerificationRequest` (final readonly)

Constructor, all parameters optional and named:

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `chainId` | `?string` | `null` | `null` → `attest.anchoring.default_chain`. Blank/whitespace rejected. |
| `fromSeq` | `int` | `1` | Must be `>= 1`. |
| `toSeq` | `?int` | `null` | `null` = open-ended. Must be `>= fromSeq` when set. |
| `minAnchor` | `?AnchorOutcome` | `null` | `null` → `attest.verification.min_anchor_outcome`. Only ranked outcomes accepted (`isRanked()`). |
| `trustedKeys` | `list<string>` | `[]` | `<key_id>=<base64>` entries, merged after `attest.verification.trusted_keys`. |
| `trustedKeyFiles` | `list<string>` | `[]` | `path` or `<key_id>=path`, merged after `attest.verification.trusted_key_files`. |
| `allowProviderDisagreement` | `bool` | `false` | OR-ed with `attest.verification.allow_provider_disagreement`. |
| `bitcoinCoreRpc` | `?string` | `null` | Header-provider override, same as the command option. |
| `bitcoinCoreCookie` | `?string` | `null` | ″ |
| `esploraUrl` | `?string` | `null` | ″ |

Invariant violations throw `\InvalidArgumentException` from the constructor. There is no
`allowUntrusted` — it never affected the outcome, only the CLI exit code, and consumers map
outcomes themselves.

### `ChainVerifier` (final)

Resolved from the container (singleton, registered by `AttestServiceProvider`). One method:

```php
public function verify(VerificationRequest $request): ChainVerificationResult;
```

Throws `\InvalidArgumentException` when no chain is named by request or configuration, when a
configured or requested minimum anchor is unparseable, or when a trusted key entry is malformed.
Other failures (header provider construction, storage) propagate as thrown.

Configuration is read at call time, not at construction, so tests and long-lived processes see
config changes.

### `ChainVerificationResult` (final readonly)

| Property | Type | Meaning |
|---|---|---|
| `outcome` | core `VerificationOutcome` | The stable outcome vocabulary, verbatim. |
| `chainId` | `string` | The chain actually verified (after default resolution). |
| `fromSeq` | `int` | As requested. |
| `toSeqRequested` | `?int` | As requested; `null` for open-ended. |
| `verifiedThroughSeq` | `?int` | Last sequence **within the requested range** whose chain structure and signature verified; `null` when none did. Set even when the policy verdict fails (e.g. `anchor_below_min`) — consult `outcome` for the verdict. When `fromSeq > 1`, core checks the predecessor's existence, stored bytes and hash link but not its signature; the claim starts at `fromSeq`. |
| `brokenAtSeq` | `?int` | Carried verbatim from core; set only for structural / signature failures (`invalid_chain`, `invalid_signature`). |
| `anchorOutcome` | `?AnchorOutcome` | Outcome of the anchor that was checked against the minimum. `null` when no minimum anchor applied, **and also** when a minimum applied but no anchor covering the range was found or an anchor could not be resolved to a driver (core reports these as `anchor_below_min` / `invalid_anchor` with no anchor verification — `message` says why). "Anchor policy was applied" is therefore not derivable from this field alone; it is derivable from the request plus configuration, which the caller owns. |
| `message` | `?string` | Core's human-readable reason, when any. |
| `verification` | core `VerificationResult` | The full underlying result for warnings, stats, signature detail. Core marks `VerificationResult` `@api`; its `anchorVerification` member is typed with a core-`@experimental` class and inherits core's stability for that member. The fields above are the stable projection and are what downstream should pin. |

Method: `isVerified(): bool` — true only for `VerificationOutcome::VERIFIED`.

`verifiedThroughSeq` derivation: `envelopeCount === 0 ? null : fromSeq + envelopeCount - 1`,
where `envelopeCount` is core's count of envelopes accepted before any break. Core does not count
the envelope that fails, so for a break at `N` the value is `N - 1` (or `null` when `N === fromSeq`).

## Out of scope

- Facade sugar (`Attest::verify(...)`) — can be added additively later.
- Verification-run persistence — ADR 0002 §8 records it as an alternative, not a request.
- Exit-code mapping — `Support\VerificationExitCode` stays `@internal`; the CLI owns exit codes.

### HTTP client resolution (new, supported)

Header providers (Bitcoin Core RPC, Esplora) reach the network. Today the adapter constructs a
Guzzle client unconditionally. With this change, header-provider construction — for the seam
**and** for `attest:verify`, since they share resolution — obtains its HTTP stack as follows:

1. If the container has a binding for `Psr\Http\Client\ClientInterface`, use it. PSR-17
   `Psr\Http\Message\RequestFactoryInterface` and `Psr\Http\Message\StreamFactoryInterface` are
   taken from the container when bound, otherwise from `GuzzleHttp\Psr7\HttpFactory`. If a
   factory is neither bound nor obtainable from `guzzlehttp/psr7`, fail with the same
   `RuntimeException` the Guzzle-absent path raises today, naming the missing binding — never a
   class-loading error. (Documented, not seam-tested: it needs `guzzlehttp/psr7` absent, which
   only the resolver's own unit tests can simulate.)
2. Otherwise, behave exactly as today (Guzzle when installed, the existing `RuntimeException`
   otherwise).

This is a documented configuration contract (listed in `STABILITY.md` beside the config keys):
a host can route header lookups through its own PSR-18 client for proxies, logging, or tests.
It is also what lets the seam's tests exercise header resolution without touching an `@internal`
class. How the resolver receives the container is implementation detail.

## Test coverage and deliberate gaps

`tests/Verification/ChainVerifierTest.php` drives everything through the container and the
published `attest.*` configuration. The header-provider tests bind a fake PSR-18 client (the
contract above) that answers Bitcoin Core and Esplora lookups and records every request, and
assert on the set of hosts contacted plus the resulting outcome.

Not covered by seam tests, and accepted as such:

- Propagation of storage failures. Nothing in the seam catches; there is nothing to assert
  beyond "exceptions propagate", which the argument-error tests already demonstrate.
- Bitcoin Core *config fallback* (as opposed to request override, which is tested): same
  `optionOrConfig` path as the tested Esplora fallback.

## Docs

`STABILITY.md` gains the three classes under the `@api` surface. `README.md` `## Verify` gains a
short programmatic example.
