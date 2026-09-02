<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Verification;

use Fissible\Attest\Anchor\AnchorOutcome;
use Fissible\Attest\Verification\VerificationOutcome;
use Fissible\Attest\Verification\VerificationResult;

/**
 * @api
 */
final readonly class ChainVerificationResult
{
    public function __construct(
        public VerificationOutcome $outcome,
        public string $chainId,
        public int $fromSeq,
        public ?int $toSeqRequested,
        public ?int $verifiedThroughSeq,
        public ?int $brokenAtSeq,
        public ?AnchorOutcome $anchorOutcome,
        public ?string $message,
        public VerificationResult $verification,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->outcome === VerificationOutcome::VERIFIED;
    }
}
