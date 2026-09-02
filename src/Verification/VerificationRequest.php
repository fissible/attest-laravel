<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Verification;

use Fissible\Attest\Anchor\AnchorOutcome;

/**
 * @api
 */
final readonly class VerificationRequest
{
    /**
     * @param list<string> $trustedKeys
     * @param list<string> $trustedKeyFiles
     */
    public function __construct(
        public ?string $chainId = null,
        public int $fromSeq = 1,
        public ?int $toSeq = null,
        public ?AnchorOutcome $minAnchor = null,
        public array $trustedKeys = [],
        public array $trustedKeyFiles = [],
        public bool $allowProviderDisagreement = false,
        public ?string $bitcoinCoreRpc = null,
        public ?string $bitcoinCoreCookie = null,
        public ?string $esploraUrl = null,
    ) {
        if ($chainId !== null && trim($chainId) === '') {
            throw new \InvalidArgumentException('Chain ID must not be blank');
        }

        if ($fromSeq < 1) {
            throw new \InvalidArgumentException('fromSeq must be >= 1');
        }

        if ($toSeq !== null && $toSeq < $fromSeq) {
            throw new \InvalidArgumentException('toSeq must be >= fromSeq');
        }

        if ($minAnchor !== null && ! $minAnchor->isRanked()) {
            throw new \InvalidArgumentException('minAnchor must be a ranked anchor outcome');
        }
    }
}
