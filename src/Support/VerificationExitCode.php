<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

use Fissible\Attest\Verification\VerificationOutcome;

final class VerificationExitCode
{
    /**
     * Spec section 13 exit-code mapping.
     *
     * Code 1 is reserved for command/config/runtime errors before a
     * VerificationOutcome exists.
     */
    public static function forOutcome(VerificationOutcome $outcome, bool $allowUntrusted = false): int
    {
        return match ($outcome) {
            VerificationOutcome::VERIFIED => 0,
            VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED => $allowUntrusted ? 0 : 2,
            VerificationOutcome::ANCHOR_BELOW_MIN => 3,
            VerificationOutcome::INVALID_CHAIN,
            VerificationOutcome::INVALID_SIGNATURE,
            VerificationOutcome::INVALID_ANCHOR => 4,
            VerificationOutcome::PROVIDER_DISAGREEMENT => 5,
        };
    }
}
