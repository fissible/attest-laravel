<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Verification;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Anchor\AnchorEnvelope;
use Fissible\Attest\Anchor\AnchorOutcome;
use Fissible\Attest\Anchor\AnchorReceipt;
use Fissible\Attest\Anchor\AnchorService;
use Fissible\Attest\Anchor\AnchorTarget;
use Fissible\Attest\Anchor\NullDriver;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsAttestation;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCodec;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsProof;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsTimestamp;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Fissible\Attest\Anchor\ProofState;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Merkle\MerkleTree;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\Attest\Verification\VerificationOutcome;
use Fissible\Attest\Verification\VerificationResult;
use Fissible\AttestLaravel\Tests\TestCase;
use Fissible\AttestLaravel\Verification\ChainVerificationResult;
use Fissible\AttestLaravel\Verification\ChainVerifier;
use Fissible\AttestLaravel\Verification\VerificationRequest;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Pins the stable programmatic verification seam (issue #9).
 *
 * Everything here goes through the container and the published `attest.*`
 * configuration, never through `@internal` collaborators: a downstream package
 * must be able to drive verification with nothing but these three classes.
 */
final class ChainVerifierTest extends TestCase
{
    use RefreshDatabase;

    private KeyPair $keyPair;
    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyPair = KeyPair::generate();
        $this->signer = new SodiumSigner($this->keyPair, 'app-prod');
    }

    // --- resolution -------------------------------------------------------

    public function test_verifier_resolves_from_the_container(): void
    {
        self::assertInstanceOf(ChainVerifier::class, $this->app->make(ChainVerifier::class));
    }

    /**
     * STABILITY.md binds the `@api` / `@internal` annotations to the supported surface
     * "class for class", so the annotation and the listing are the contract, not decoration.
     */
    public function test_seam_classes_are_annotated_and_listed_as_supported_api(): void
    {
        $stability = file_get_contents(__DIR__ . '/../../STABILITY.md');
        self::assertIsString($stability);

        foreach ([ChainVerifier::class, VerificationRequest::class, ChainVerificationResult::class] as $class) {
            $doc = (new \ReflectionClass($class))->getDocComment();

            self::assertIsString($doc, "$class has no docblock");
            self::assertStringContainsString('@api', $doc, "$class is not annotated @api");
            self::assertStringNotContainsString('@internal', $doc, "$class is annotated @internal");

            $short = 'Verification\\' . (new \ReflectionClass($class))->getShortName();
            self::assertStringContainsString("`$short`", $stability, "$short is not listed in STABILITY.md");
        }

        self::assertStringContainsString(
            'Psr\\Http\\Client\\ClientInterface',
            $stability,
            'the container-bound PSR-18 client seam is not documented in STABILITY.md',
        );
    }

    // --- verified outcomes ------------------------------------------------

    public function test_trusted_chain_verifies_through_its_last_sequence(): void
    {
        $this->buildChain('trusted', 3);

        $result = $this->verify(new VerificationRequest(
            chainId: 'trusted',
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertInstanceOf(ChainVerificationResult::class, $result);
        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertTrue($result->isVerified());
        self::assertSame('trusted', $result->chainId);
        self::assertSame(1, $result->fromSeq);
        self::assertNull($result->toSeqRequested);
        self::assertSame(3, $result->verifiedThroughSeq);
        self::assertNull($result->brokenAtSeq);
        self::assertNull($result->anchorOutcome);
        self::assertNull($result->message);
    }

    public function test_result_carries_the_core_verification_result_for_detail(): void
    {
        $this->buildChain('detail', 2);

        $result = $this->verify(new VerificationRequest(
            chainId: 'detail',
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertInstanceOf(VerificationResult::class, $result->verification);
        self::assertSame($result->outcome, $result->verification->outcome);
        self::assertSame('detail', $result->verification->chainStats->chainId);
        self::assertSame(2, $result->verification->chainStats->envelopeCount);
        self::assertSame(2, $result->verification->chainStats->trustedSignatureCount);
    }

    public function test_explicit_sub_range_is_reported_as_requested(): void
    {
        $this->buildChain('ranged', 4);

        $result = $this->verify(new VerificationRequest(
            chainId: 'ranged',
            fromSeq: 2,
            toSeq: 3,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertSame(2, $result->fromSeq);
        self::assertSame(3, $result->toSeqRequested);
        self::assertSame(3, $result->verifiedThroughSeq);
        self::assertNull($result->brokenAtSeq);
    }

    public function test_untrusted_signatures_verify_integrity_only(): void
    {
        $this->buildChain('untrusted', 2);

        $result = $this->verify(new VerificationRequest(chainId: 'untrusted'));

        self::assertSame(VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, $result->outcome);
        self::assertFalse($result->isVerified());
        self::assertSame(2, $result->verifiedThroughSeq);
        self::assertNull($result->brokenAtSeq);
        self::assertNotNull($result->message);
    }

    // --- broken chains ----------------------------------------------------

    public function test_missing_envelope_reports_broken_at_and_verified_through(): void
    {
        $this->buildChain('gapped', 4);
        $this->deleteEnvelope('gapped', 3);

        $result = $this->verify(new VerificationRequest(
            chainId: 'gapped',
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::INVALID_CHAIN, $result->outcome);
        self::assertFalse($result->isVerified());
        self::assertSame(2, $result->verifiedThroughSeq);
        self::assertSame(3, $result->brokenAtSeq);
        self::assertNull($result->anchorOutcome);
        self::assertNotNull($result->message);
    }

    public function test_tampered_envelope_is_broken_at_its_own_sequence(): void
    {
        $this->buildChain('tampered', 3);
        $this->tamperSignature('tampered', 2);

        $result = $this->verify(new VerificationRequest(
            chainId: 'tampered',
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::INVALID_SIGNATURE, $result->outcome);
        self::assertFalse($result->isVerified());
        self::assertSame(1, $result->verifiedThroughSeq);
        self::assertSame(2, $result->brokenAtSeq);
        self::assertNull($result->anchorOutcome);
    }

    public function test_truncated_explicit_range_is_broken_at_the_first_missing_sequence(): void
    {
        $this->buildChain('short', 2);

        $result = $this->verify(new VerificationRequest(
            chainId: 'short',
            fromSeq: 1,
            toSeq: 4,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::INVALID_CHAIN, $result->outcome);
        self::assertSame(4, $result->toSeqRequested);
        self::assertSame(2, $result->verifiedThroughSeq);
        self::assertSame(3, $result->brokenAtSeq);
    }

    public function test_unknown_chain_verifies_through_nothing(): void
    {
        $result = $this->verify(new VerificationRequest(
            chainId: 'never-written',
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::INVALID_CHAIN, $result->outcome);
        self::assertSame('never-written', $result->chainId);
        self::assertNull($result->verifiedThroughSeq);
        self::assertSame(1, $result->brokenAtSeq);
    }

    // --- anchors ----------------------------------------------------------

    public function test_anchor_outcome_is_null_when_no_anchor_policy_applies(): void
    {
        $this->buildChain('unanchored', 2);
        $this->anchorLocalOnly('unanchored', 1, 2);

        $result = $this->verify(new VerificationRequest(
            chainId: 'unanchored',
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertNull($result->anchorOutcome);
    }

    public function test_anchor_outcome_is_reported_when_the_minimum_is_met(): void
    {
        $this->buildChain('anchored', 2);
        $this->anchorLocalOnly('anchored', 1, 2);

        $result = $this->verify(new VerificationRequest(
            chainId: 'anchored',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::LOCAL_ONLY,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertTrue($result->isVerified());
        self::assertSame(AnchorOutcome::LOCAL_ONLY, $result->anchorOutcome);
        self::assertSame(2, $result->verifiedThroughSeq);
    }

    public function test_anchor_below_minimum_keeps_structural_extent_but_is_not_verified(): void
    {
        $this->buildChain('below-min', 2);
        $this->anchorLocalOnly('below-min', 1, 2);

        $result = $this->verify(new VerificationRequest(
            chainId: 'below-min',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::PENDING,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::ANCHOR_BELOW_MIN, $result->outcome);
        self::assertFalse($result->isVerified());
        self::assertSame(AnchorOutcome::LOCAL_ONLY, $result->anchorOutcome);
        self::assertSame(2, $result->verifiedThroughSeq);
        self::assertNull($result->brokenAtSeq);
        self::assertNotNull($result->message);
    }

    public function test_missing_anchor_under_a_minimum_reports_no_anchor_outcome(): void
    {
        $this->buildChain('never-anchored', 2);

        $result = $this->verify(new VerificationRequest(
            chainId: 'never-anchored',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::LOCAL_ONLY,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::ANCHOR_BELOW_MIN, $result->outcome);
        self::assertFalse($result->isVerified());
        self::assertNull($result->anchorOutcome);
        self::assertSame(2, $result->verifiedThroughSeq);
        self::assertNull($result->brokenAtSeq);
        self::assertNotNull($result->message);
    }

    // --- header providers (resolution parity with attest:verify) ----------
    //
    // These go over the network in production. The seam honours a PSR-18 client
    // bound on the container, so the tests bind a fake that records every request
    // and answers as Bitcoin Core / Esplora would. Assertions are on which hosts
    // were contacted and on the outcome, never on the provider internals.

    public function test_configured_esplora_url_is_the_only_header_source_consulted(): void
    {
        $root = $this->buildOtsUpgradedChain('esplora-config');
        $network = $this->bindFakeHeaderNetwork(esploraMerkleRoot: $root, coreMerkleRoot: $root);
        config()->set('attest.headers.esplora_url', 'https://configured.esplora.test/api');

        $result = $this->verify(new VerificationRequest(
            chainId: 'esplora-config',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::REMOTE_HEADER_CONFIRMED,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(['configured.esplora.test'], $network->hosts());
        self::assertSame('https://configured.esplora.test/api/blocks/tip/height', $network->urls()[0] ?? null);
        self::assertGreaterThan(0, $network->requestsCreated, 'container-bound RequestFactoryInterface was not used');
        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertSame(AnchorOutcome::REMOTE_HEADER_CONFIRMED, $result->anchorOutcome);
        self::assertSame(2, $result->verifiedThroughSeq);
    }

    public function test_request_esplora_url_replaces_the_configured_one(): void
    {
        $root = $this->buildOtsUpgradedChain('esplora-override');
        $network = $this->bindFakeHeaderNetwork(esploraMerkleRoot: $root, coreMerkleRoot: $root);
        config()->set('attest.headers.esplora_url', 'https://configured.esplora.test/api');

        $result = $this->verify(new VerificationRequest(
            chainId: 'esplora-override',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::REMOTE_HEADER_CONFIRMED,
            trustedKeys: [$this->trustedKeyEntry()],
            esploraUrl: 'https://override.esplora.test/api',
        ));

        self::assertSame(['override.esplora.test'], $network->hosts());
        self::assertSame('https://override.esplora.test/api/blocks/tip/height', $network->urls()[0] ?? null);
        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
    }

    public function test_request_bitcoin_core_rpc_replaces_the_configured_one(): void
    {
        $root = $this->buildOtsUpgradedChain('core-override');
        $network = $this->bindFakeHeaderNetwork(esploraMerkleRoot: $root, coreMerkleRoot: $root);
        config()->set('attest.headers.bitcoin_core_rpc', 'http://configured.core.test:8332');

        $result = $this->verify(new VerificationRequest(
            chainId: 'core-override',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::BITCOIN_VERIFIED,
            trustedKeys: [$this->trustedKeyEntry()],
            bitcoinCoreRpc: 'http://override.core.test:8332',
        ));

        self::assertSame(['override.core.test'], $network->hosts());
        self::assertSame('http://override.core.test:8332/', $network->urls()[0] ?? null);
        self::assertGreaterThan(0, $network->requestsCreated, 'container-bound RequestFactoryInterface was not used');
        self::assertGreaterThan(0, $network->streamsCreated, 'container-bound StreamFactoryInterface was not used');
        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertSame(AnchorOutcome::BITCOIN_VERIFIED, $result->anchorOutcome);
    }

    public function test_request_bitcoin_core_cookie_authenticates_the_rpc_calls(): void
    {
        $root = $this->buildOtsUpgradedChain('core-cookie');
        $network = $this->bindFakeHeaderNetwork(esploraMerkleRoot: $root, coreMerkleRoot: $root);
        $cookie = tempnam(sys_get_temp_dir(), 'attest-cookie-');
        self::assertIsString($cookie);
        file_put_contents($cookie, '__cookie__:s3cret');

        try {
            $result = $this->verify(new VerificationRequest(
                chainId: 'core-cookie',
                fromSeq: 1,
                toSeq: 2,
                minAnchor: AnchorOutcome::BITCOIN_VERIFIED,
                trustedKeys: [$this->trustedKeyEntry()],
                bitcoinCoreRpc: 'http://core.test:8332',
                bitcoinCoreCookie: $cookie,
            ));
        } finally {
            @unlink($cookie);
        }

        self::assertSame(['core.test'], $network->hosts());
        self::assertNotSame([], $network->headerValues('Authorization'));
        foreach ($network->headerValues('Authorization') as $value) {
            self::assertSame('Basic ' . base64_encode('__cookie__:s3cret'), $value);
        }
        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
    }

    public function test_provider_disagreement_fails_verification_by_default(): void
    {
        $root = $this->buildOtsUpgradedChain('disagree');
        $network = $this->bindFakeHeaderNetwork(esploraMerkleRoot: str_repeat('f', 64), coreMerkleRoot: $root);
        config()->set('attest.headers.bitcoin_core_rpc', 'http://core.test:8332');
        config()->set('attest.headers.esplora_url', 'https://esplora.test/api');

        $result = $this->verify(new VerificationRequest(
            chainId: 'disagree',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::REMOTE_HEADER_CONFIRMED,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(['core.test', 'esplora.test'], $network->hosts());
        self::assertSame(VerificationOutcome::PROVIDER_DISAGREEMENT, $result->outcome);
        self::assertFalse($result->isVerified());
        self::assertSame(AnchorOutcome::PROVIDER_DISAGREEMENT, $result->anchorOutcome);
        self::assertSame(2, $result->verifiedThroughSeq);
        self::assertNull($result->brokenAtSeq);
    }

    public function test_request_can_allow_provider_disagreement(): void
    {
        $root = $this->buildOtsUpgradedChain('allow-request');
        $this->bindFakeHeaderNetwork(esploraMerkleRoot: str_repeat('f', 64), coreMerkleRoot: $root);
        config()->set('attest.headers.bitcoin_core_rpc', 'http://core.test:8332');
        config()->set('attest.headers.esplora_url', 'https://esplora.test/api');

        $result = $this->verify(new VerificationRequest(
            chainId: 'allow-request',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::REMOTE_HEADER_CONFIRMED,
            trustedKeys: [$this->trustedKeyEntry()],
            allowProviderDisagreement: true,
        ));

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertSame(AnchorOutcome::BITCOIN_VERIFIED, $result->anchorOutcome);
    }

    public function test_configuration_can_allow_provider_disagreement(): void
    {
        $root = $this->buildOtsUpgradedChain('allow-config');
        $this->bindFakeHeaderNetwork(esploraMerkleRoot: str_repeat('f', 64), coreMerkleRoot: $root);
        config()->set('attest.headers.bitcoin_core_rpc', 'http://core.test:8332');
        config()->set('attest.headers.esplora_url', 'https://esplora.test/api');
        config()->set('attest.verification.allow_provider_disagreement', true);

        $result = $this->verify(new VerificationRequest(
            chainId: 'allow-config',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::REMOTE_HEADER_CONFIRMED,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertSame(AnchorOutcome::BITCOIN_VERIFIED, $result->anchorOutcome);
    }

    // --- configuration resolution (must match attest:verify) --------------

    public function test_default_chain_comes_from_configuration_when_the_request_names_none(): void
    {
        $this->buildChain('configured-default', 2);
        config()->set('attest.anchoring.default_chain', 'configured-default');

        $result = $this->verify(new VerificationRequest(trustedKeys: [$this->trustedKeyEntry()]));

        self::assertSame('configured-default', $result->chainId);
        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
    }

    public function test_request_chain_overrides_configured_default(): void
    {
        $this->buildChain('explicit', 1);
        config()->set('attest.anchoring.default_chain', 'never-written');

        $result = $this->verify(new VerificationRequest(
            chainId: 'explicit',
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame('explicit', $result->chainId);
        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
    }

    public function test_no_chain_anywhere_is_an_argument_error(): void
    {
        config()->set('attest.anchoring.default_chain', null);

        $this->expectException(\InvalidArgumentException::class);

        $this->verify(new VerificationRequest());
    }

    public function test_blank_configured_default_chain_counts_as_none(): void
    {
        config()->set('attest.anchoring.default_chain', '   ');

        $this->expectException(\InvalidArgumentException::class);

        $this->verify(new VerificationRequest());
    }

    public function test_configuration_is_read_at_call_time_not_at_construction(): void
    {
        $this->buildChain('late-config', 1);
        $verifier = $this->app->make(ChainVerifier::class);
        self::assertInstanceOf(ChainVerifier::class, $verifier);

        $before = $verifier->verify(new VerificationRequest(chainId: 'late-config'));
        self::assertSame(VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, $before->outcome);

        config()->set('attest.verification.trusted_keys', [$this->trustedKeyEntry()]);
        config()->set('attest.anchoring.default_chain', 'late-config');

        $after = $verifier->verify(new VerificationRequest());
        self::assertSame('late-config', $after->chainId);
        self::assertSame(VerificationOutcome::VERIFIED, $after->outcome);
    }

    public function test_trusted_keys_come_from_configuration(): void
    {
        $this->buildChain('config-keys', 2);
        config()->set('attest.verification.trusted_keys', [$this->trustedKeyEntry()]);

        $result = $this->verify(new VerificationRequest(chainId: 'config-keys'));

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertSame(2, $result->verification->chainStats->trustedSignatureCount);
    }

    public function test_request_trusted_keys_merge_with_configured_ones(): void
    {
        // Sequence 1 is signed by the configured key, sequence 2 by the request key.
        // Only the union trusts both; either source alone leaves one signature untrusted.
        $secondKeyPair = KeyPair::generate();
        $this->buildChain('merged-keys', 1);
        EvidenceChain::open($this->store(), 'merged-keys', new SodiumSigner($secondKeyPair, 'app-next'))
            ->record('app.event', ['n' => 2]);
        config()->set('attest.verification.trusted_keys', [$this->trustedKeyEntry()]);
        $requestKey = 'app-next=' . Base64::encode($secondKeyPair->publicKey);

        $configOnly = $this->verify(new VerificationRequest(chainId: 'merged-keys'));
        self::assertSame(VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, $configOnly->outcome);

        $merged = $this->verify(new VerificationRequest(
            chainId: 'merged-keys',
            trustedKeys: [$requestKey],
        ));

        self::assertSame(VerificationOutcome::VERIFIED, $merged->outcome);
        self::assertSame(2, $merged->verification->chainStats->trustedSignatureCount);
    }

    public function test_malformed_configured_trusted_key_is_an_argument_error(): void
    {
        $this->buildChain('bad-config-key', 1);
        config()->set('attest.verification.trusted_keys', ['prod=not-base64!']);

        $this->expectException(\InvalidArgumentException::class);

        $this->verify(new VerificationRequest(chainId: 'bad-config-key'));
    }

    public function test_missing_configured_trusted_key_file_is_an_argument_error(): void
    {
        $this->buildChain('missing-key-file', 1);
        config()->set('attest.verification.trusted_key_files', ['prod=' . sys_get_temp_dir() . '/attest-no-such-key.pub']);

        $this->expectException(\InvalidArgumentException::class);

        $this->verify(new VerificationRequest(chainId: 'missing-key-file'));
    }

    public function test_trusted_key_files_come_from_configuration(): void
    {
        $this->buildChain('config-key-files', 1);
        $path = $this->writeKeyFile($this->keyPair);
        config()->set('attest.verification.trusted_key_files', ['app-prod=' . $path]);

        try {
            $result = $this->verify(new VerificationRequest(chainId: 'config-key-files'));
        } finally {
            @unlink($path);
        }

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
    }

    public function test_request_trusted_key_files_merge_with_configured_ones(): void
    {
        // Sequence 1 is signed by the configured file's key, sequence 2 by the request file's key.
        $secondKeyPair = KeyPair::generate();
        $this->buildChain('merged-key-files', 1);
        EvidenceChain::open($this->store(), 'merged-key-files', new SodiumSigner($secondKeyPair, 'app-next'))
            ->record('app.event', ['n' => 2]);

        $configuredPath = $this->writeKeyFile($this->keyPair);
        $requestPath = $this->writeKeyFile($secondKeyPair);
        config()->set('attest.verification.trusted_key_files', ['app-prod=' . $configuredPath]);

        try {
            $configOnly = $this->verify(new VerificationRequest(chainId: 'merged-key-files'));
            self::assertSame(VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, $configOnly->outcome);

            $merged = $this->verify(new VerificationRequest(
                chainId: 'merged-key-files',
                trustedKeyFiles: ['app-next=' . $requestPath],
            ));
        } finally {
            @unlink($configuredPath);
            @unlink($requestPath);
        }

        self::assertSame(VerificationOutcome::VERIFIED, $merged->outcome);
        self::assertSame(2, $merged->verification->chainStats->trustedSignatureCount);
    }

    public function test_require_trusted_key_can_be_disabled_by_configuration(): void
    {
        $this->buildChain('lenient', 2);
        config()->set('attest.verification.require_trusted_key', false);

        $result = $this->verify(new VerificationRequest(chainId: 'lenient'));

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertSame(2, $result->verification->chainStats->untrustedSignatureCount);
    }

    public function test_minimum_anchor_comes_from_configuration_when_the_request_sets_none(): void
    {
        $this->buildChain('config-min', 2);
        $this->anchorLocalOnly('config-min', 1, 2);
        config()->set('attest.verification.min_anchor_outcome', 'pending');

        $result = $this->verify(new VerificationRequest(
            chainId: 'config-min',
            fromSeq: 1,
            toSeq: 2,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::ANCHOR_BELOW_MIN, $result->outcome);
        self::assertSame(AnchorOutcome::LOCAL_ONLY, $result->anchorOutcome);
    }

    public function test_request_minimum_anchor_overrides_configuration(): void
    {
        $this->buildChain('request-min', 2);
        $this->anchorLocalOnly('request-min', 1, 2);
        config()->set('attest.verification.min_anchor_outcome', 'pending');

        $result = $this->verify(new VerificationRequest(
            chainId: 'request-min',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: AnchorOutcome::LOCAL_ONLY,
            trustedKeys: [$this->trustedKeyEntry()],
        ));

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertSame(AnchorOutcome::LOCAL_ONLY, $result->anchorOutcome);
    }

    public function test_invalid_configured_minimum_anchor_is_an_argument_error(): void
    {
        $this->buildChain('bad-config-min', 1);
        config()->set('attest.verification.min_anchor_outcome', 'blockchain_verified');

        $this->expectException(\InvalidArgumentException::class);

        $this->verify(new VerificationRequest(
            chainId: 'bad-config-min',
            trustedKeys: [$this->trustedKeyEntry()],
        ));
    }

    public function test_malformed_trusted_key_is_an_argument_error(): void
    {
        $this->buildChain('bad-key', 1);

        $this->expectException(\InvalidArgumentException::class);

        $this->verify(new VerificationRequest(
            chainId: 'bad-key',
            trustedKeys: ['not-a-key-entry'],
        ));
    }

    // --- helpers ----------------------------------------------------------

    private function verify(VerificationRequest $request): ChainVerificationResult
    {
        $verifier = $this->app->make(ChainVerifier::class);
        self::assertInstanceOf(ChainVerifier::class, $verifier);

        return $verifier->verify($request);
    }

    private function store(): ChainStore
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(ChainStore::class, $store);

        return $store;
    }

    private function trustedKeyEntry(): string
    {
        return 'app-prod=' . Base64::encode($this->keyPair->publicKey);
    }

    /**
     * @return list<SignedEnvelope>
     */
    private function buildChain(string $chainId, int $count): array
    {
        $chain = EvidenceChain::open($this->store(), $chainId, $this->signer);
        $records = [];
        for ($i = 1; $i <= $count; $i++) {
            $records[] = $chain->record('app.event', ['n' => $i]);
        }

        return $records;
    }

    private function anchorLocalOnly(string $chainId, int $fromSeq, int $toSeq): void
    {
        $claimStore = $this->app->make(AnchorClaimStore::class);
        self::assertInstanceOf(AnchorClaimStore::class, $claimStore);

        (new AnchorService($this->store(), $claimStore, $this->signer, claimedBy: 'chain-verifier-test'))
            ->anchorRange($chainId, $fromSeq, $toSeq, new NullDriver());
    }

    private const ATTESTED_HEIGHT = 840000;

    /**
     * Binds one fake object as the container's PSR-18 client and both PSR-17 factories.
     * It answers Bitcoin Core JSON-RPC and Esplora REST lookups for the attested height,
     * with the merkle root each provider should report, records every request it sends,
     * and counts the requests and streams it was asked to create — so a test can prove
     * the bound factories were used, not just the bound client.
     */
    private function bindFakeHeaderNetwork(string $esploraMerkleRoot, string $coreMerkleRoot): FakeHeaderNetwork
    {
        $network = new FakeHeaderNetwork(self::ATTESTED_HEIGHT, $esploraMerkleRoot, $coreMerkleRoot);

        $this->app->instance(ClientInterface::class, $network);
        $this->app->instance(RequestFactoryInterface::class, $network);
        $this->app->instance(StreamFactoryInterface::class, $network);

        return $network;
    }

    /**
     * Two application envelopes followed by an OpenTimestamps anchor envelope whose
     * receipt carries a Bitcoin attestation at ATTESTED_HEIGHT, so any header-backed
     * minimum forces a provider lookup. Returns the anchored merkle root (hex).
     */
    private function buildOtsUpgradedChain(string $chainId): string
    {
        $records = $this->buildChain($chainId, 2);
        $target = new AnchorTarget(
            chainId: $chainId,
            fromSeq: 1,
            toSeq: 2,
            merkleAlgorithm: MerkleTree::ALGORITHM,
            rootHex: MerkleTree::rootHex(array_map(
                static fn (SignedEnvelope $signed): string => $signed->signedCanonicalBytes(),
                $records,
            )),
        );
        $rootBytes = hex2bin($target->rootHex);
        self::assertIsString($rootBytes);
        $timestamp = (new OpenTimestampsTimestamp($rootBytes))
            ->withAttestation(OpenTimestampsAttestation::bitcoin(self::ATTESTED_HEIGHT));
        $receipt = new AnchorReceipt(
            driverName: OpenTimestampsDriver::NAME,
            target: $target,
            state: ProofState::UPGRADED,
            receiptBytes: OpenTimestampsCodec::encodeDetached(new OpenTimestampsProof($rootBytes, $timestamp)),
            createdAtIso8601: '2026-09-01T00:00:00.000Z',
        );

        EvidenceChain::open($this->store(), $chainId, $this->signer)
            ->record(AnchorEnvelope::UPGRADED_TYPE, AnchorEnvelope::upgradedPayload($receipt));

        return $target->rootHex;
    }

    private function writeKeyFile(KeyPair $keyPair): string
    {
        $path = tempnam(sys_get_temp_dir(), 'attest-key-');
        self::assertIsString($path);
        file_put_contents($path, Base64::encode($keyPair->publicKey));

        return $path;
    }

    private function deleteEnvelope(string $chainId, int $sequence): void
    {
        $deleted = DB::table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', $sequence)
            ->delete();

        self::assertSame(1, $deleted);
    }

    private function tamperSignature(string $chainId, int $sequence): void
    {
        $raw = DB::table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', $sequence)
            ->value('raw_envelope');
        self::assertIsString($raw);

        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['sig'] = 'base64:' . Base64::encode(str_repeat("\x00", 64));

        DB::table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', $sequence)
            ->update(['raw_envelope' => json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }
}

/**
 * A PSR-18 client standing in for the Bitcoin network. Answers Bitcoin Core JSON-RPC
 * (POST) and Esplora REST (GET) lookups for one attested height and records every
 * request. Each provider is told its own merkle root so the tests can make them agree
 * or disagree.
 */
final class FakeHeaderNetwork implements ClientInterface, RequestFactoryInterface, StreamFactoryInterface
{
    private const BLOCK_HASH = '00000000000000000002a7c4c1e48d76c5a37902165a270156b7a8d72728a054';

    /** @var list<RequestInterface> */
    private array $requests = [];

    public int $requestsCreated = 0;
    public int $streamsCreated = 0;

    private readonly HttpFactory $psr17;

    public function __construct(
        private readonly int $height,
        private readonly string $esploraMerkleRoot,
        private readonly string $coreMerkleRoot,
    ) {
        $this->psr17 = new HttpFactory();
    }

    /** @return list<string> the named header's value on every request that carried it, in order */
    public function headerValues(string $name): array
    {
        $values = [];
        foreach ($this->requests as $request) {
            if ($request->hasHeader($name)) {
                $values[] = $request->getHeaderLine($name);
            }
        }

        return $values;
    }

    /** @return list<string> every request URL, in order */
    public function urls(): array
    {
        return array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests);
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        $this->requestsCreated++;

        return $this->psr17->createRequest($method, $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        $this->streamsCreated++;

        return $this->psr17->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->psr17->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->psr17->createStreamFromResource($resource);
    }

    /** @return list<string> distinct hosts in first-contact order */
    public function hosts(): array
    {
        $hosts = [];
        foreach ($this->requests as $request) {
            $host = $request->getUri()->getHost();
            if (! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $request->getMethod() === 'POST'
            ? $this->bitcoinCore($request)
            : $this->esplora($request->getUri()->getPath());
    }

    private function bitcoinCore(RequestInterface $request): ResponseInterface
    {
        $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $method = is_array($payload) ? ($payload['method'] ?? null) : null;

        $result = match ($method) {
            'getblockcount' => $this->height + 100,
            'getblockhash' => self::BLOCK_HASH,
            'getblockheader' => [
                'hash' => self::BLOCK_HASH,
                'height' => $this->height,
                'confirmations' => 101,
                'merkleroot' => $this->coreMerkleRoot,
                'time' => 1713571200,
            ],
            default => throw new \RuntimeException('FakeHeaderNetwork: unexpected RPC method ' . var_export($method, true)),
        };

        return $this->json(['result' => $result, 'error' => null, 'id' => is_array($payload) ? ($payload['id'] ?? 1) : 1]);
    }

    private function esplora(string $path): ResponseInterface
    {
        $hash = self::BLOCK_HASH;

        return match (true) {
            str_ends_with($path, '/blocks/tip/height') => $this->text((string) ($this->height + 100)),
            str_ends_with($path, '/block-height/' . $this->height) => $this->text($hash),
            str_ends_with($path, "/block/$hash/status") => $this->json(['in_best_chain' => true]),
            str_ends_with($path, "/block/$hash") => $this->json([
                'id' => $hash,
                'height' => $this->height,
                'merkle_root' => $this->esploraMerkleRoot,
                'timestamp' => 1713571200,
            ]),
            default => throw new \RuntimeException("FakeHeaderNetwork: unexpected Esplora path $path"),
        };
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function text(string $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain'], $body);
    }
}
