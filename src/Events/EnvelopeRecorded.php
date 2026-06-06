<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Events;

use Fissible\Attest\Envelope\SignedEnvelope;

final readonly class EnvelopeRecorded
{
    public function __construct(
        public string $chainId,
        public SignedEnvelope $signed,
    ) {
    }
}
