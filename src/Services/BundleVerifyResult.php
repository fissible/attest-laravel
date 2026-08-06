<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

use Fissible\Attest\Verification\VerificationResult;

/**
 * @internal
 */
final readonly class BundleVerifyResult
{
    public function __construct(
        public string $bundlePath,
        public string $chainId,
        public int $fromSeq,
        public int $toSeq,
        public VerificationResult $verification,
    ) {
    }
}
