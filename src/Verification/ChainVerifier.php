<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Verification;

use Fissible\AttestLaravel\Services\VerifyChain;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * @api
 */
final class ChainVerifier
{
    public function __construct(
        private readonly VerifyChain $verifyChain,
        private readonly ConfigRepository $config,
    ) {
    }

    public function verify(VerificationRequest $request): ChainVerificationResult
    {
        $chainId = $request->chainId ?? $this->configuredChainId();
        if ($chainId === null) {
            throw new \InvalidArgumentException('A chain ID is required or attest.anchoring.default_chain must be configured');
        }

        $verification = $this->verifyChain->verify(
            chainId: $chainId,
            fromSeq: $request->fromSeq,
            toSeq: $request->toSeq,
            minAnchor: $request->minAnchor?->value,
            trustedKeys: $request->trustedKeys,
            trustedKeyFiles: $request->trustedKeyFiles,
            allowProviderDisagreement: $request->allowProviderDisagreement,
            bitcoinCoreRpc: $request->bitcoinCoreRpc,
            bitcoinCoreCookie: $request->bitcoinCoreCookie,
            esploraUrl: $request->esploraUrl,
        );

        $envelopeCount = $verification->chainStats->envelopeCount;

        return new ChainVerificationResult(
            outcome: $verification->outcome,
            chainId: $chainId,
            fromSeq: $request->fromSeq,
            toSeqRequested: $request->toSeq,
            verifiedThroughSeq: $envelopeCount === 0 ? null : $request->fromSeq + $envelopeCount - 1,
            brokenAtSeq: $verification->brokenAtSeq,
            anchorOutcome: $verification->anchorVerification?->outcome,
            message: $verification->message,
            verification: $verification,
        );
    }

    private function configuredChainId(): ?string
    {
        $configured = $this->config->get('attest.anchoring.default_chain');
        if (! is_string($configured)) {
            return null;
        }

        $configured = trim($configured);

        return $configured === '' ? null : $configured;
    }
}
