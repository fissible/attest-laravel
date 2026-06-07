<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Support;

use Fissible\Attest\Verification\VerificationOutcome;
use Fissible\AttestLaravel\Support\VerificationExitCode;
use Fissible\AttestLaravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class VerificationExitCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{VerificationOutcome, int}>
     */
    public static function outcomes(): iterable
    {
        yield 'verified' => [VerificationOutcome::VERIFIED, 0];
        yield 'untrusted' => [VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, 2];
        yield 'anchor below min' => [VerificationOutcome::ANCHOR_BELOW_MIN, 3];
        yield 'invalid chain' => [VerificationOutcome::INVALID_CHAIN, 4];
        yield 'invalid signature' => [VerificationOutcome::INVALID_SIGNATURE, 4];
        yield 'invalid anchor' => [VerificationOutcome::INVALID_ANCHOR, 4];
        yield 'provider disagreement' => [VerificationOutcome::PROVIDER_DISAGREEMENT, 5];
    }

    #[DataProvider('outcomes')]
    public function test_maps_verification_outcomes_to_spec_exit_codes(
        VerificationOutcome $outcome,
        int $expected,
    ): void {
        self::assertSame($expected, VerificationExitCode::forOutcome($outcome));
    }

    public function test_allow_untrusted_treats_untrusted_integrity_as_success(): void
    {
        self::assertSame(
            0,
            VerificationExitCode::forOutcome(VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, allowUntrusted: true),
        );
    }
}
