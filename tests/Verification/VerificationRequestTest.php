<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Verification;

use Fissible\Attest\Anchor\AnchorOutcome;
use Fissible\AttestLaravel\Verification\VerificationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerificationRequestTest extends TestCase
{
    public function test_defaults_describe_an_open_ended_verification_of_the_configured_chain(): void
    {
        $request = new VerificationRequest();

        self::assertNull($request->chainId);
        self::assertSame(1, $request->fromSeq);
        self::assertNull($request->toSeq);
        self::assertNull($request->minAnchor);
        self::assertSame([], $request->trustedKeys);
        self::assertSame([], $request->trustedKeyFiles);
        self::assertFalse($request->allowProviderDisagreement);
        self::assertNull($request->bitcoinCoreRpc);
        self::assertNull($request->bitcoinCoreCookie);
        self::assertNull($request->esploraUrl);
    }

    public function test_explicit_values_are_carried_verbatim(): void
    {
        $request = new VerificationRequest(
            chainId: 'tenant:5',
            fromSeq: 3,
            toSeq: 9,
            minAnchor: AnchorOutcome::PENDING,
            trustedKeys: ['prod=abc'],
            trustedKeyFiles: ['prod=/keys/prod.pub'],
            allowProviderDisagreement: true,
            bitcoinCoreRpc: 'http://127.0.0.1:8332',
            bitcoinCoreCookie: '/var/lib/bitcoin/.cookie',
            esploraUrl: 'https://esplora.example',
        );

        self::assertSame('tenant:5', $request->chainId);
        self::assertSame(3, $request->fromSeq);
        self::assertSame(9, $request->toSeq);
        self::assertSame(AnchorOutcome::PENDING, $request->minAnchor);
        self::assertSame(['prod=abc'], $request->trustedKeys);
        self::assertSame(['prod=/keys/prod.pub'], $request->trustedKeyFiles);
        self::assertTrue($request->allowProviderDisagreement);
        self::assertSame('http://127.0.0.1:8332', $request->bitcoinCoreRpc);
        self::assertSame('/var/lib/bitcoin/.cookie', $request->bitcoinCoreCookie);
        self::assertSame('https://esplora.example', $request->esploraUrl);
    }

    public function test_to_seq_may_equal_from_seq(): void
    {
        $request = new VerificationRequest(chainId: 'c', fromSeq: 4, toSeq: 4);

        self::assertSame(4, $request->fromSeq);
        self::assertSame(4, $request->toSeq);
    }

    #[DataProvider('invalidFromSeq')]
    public function test_from_seq_below_one_is_rejected(int $fromSeq): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VerificationRequest(chainId: 'c', fromSeq: $fromSeq);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidFromSeq(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function test_to_seq_below_from_seq_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VerificationRequest(chainId: 'c', fromSeq: 5, toSeq: 4);
    }

    public function test_to_seq_below_one_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VerificationRequest(chainId: 'c', fromSeq: 1, toSeq: 0);
    }

    #[DataProvider('blankChainIds')]
    public function test_blank_chain_id_is_rejected_rather_than_silently_defaulted(string $chainId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VerificationRequest(chainId: $chainId);
    }

    /** @return iterable<string, array{string}> */
    public static function blankChainIds(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    #[DataProvider('unrankedAnchorOutcomes')]
    public function test_unranked_anchor_outcomes_cannot_be_a_minimum(AnchorOutcome $outcome): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VerificationRequest(chainId: 'c', minAnchor: $outcome);
    }

    /** @return iterable<string, array{AnchorOutcome}> */
    public static function unrankedAnchorOutcomes(): iterable
    {
        yield 'invalid' => [AnchorOutcome::INVALID];
        yield 'provider_disagreement' => [AnchorOutcome::PROVIDER_DISAGREEMENT];
    }

    #[DataProvider('rankedAnchorOutcomes')]
    public function test_every_ranked_anchor_outcome_is_an_acceptable_minimum(AnchorOutcome $outcome): void
    {
        $request = new VerificationRequest(chainId: 'c', minAnchor: $outcome);

        self::assertSame($outcome, $request->minAnchor);
    }

    /** @return iterable<string, array{AnchorOutcome}> */
    public static function rankedAnchorOutcomes(): iterable
    {
        foreach (AnchorOutcome::cases() as $case) {
            if ($case->isRanked()) {
                yield $case->value => [$case];
            }
        }
    }

    public function test_request_is_immutable(): void
    {
        $request = new VerificationRequest(chainId: 'c');

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line intentional write to a readonly property
        $request->chainId = 'other';
    }
}
