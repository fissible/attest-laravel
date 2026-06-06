<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Support;

use Fissible\AttestLaravel\Support\Timestamp;
use PHPUnit\Framework\TestCase;

final class TimestampTest extends TestCase
{
    public function test_format_preserves_microseconds(): void
    {
        $t = new \DateTimeImmutable('2026-06-06T14:32:11.123456Z');
        self::assertSame('2026-06-06 14:32:11.123456', Timestamp::format($t));
    }

    public function test_format_normalizes_to_utc(): void
    {
        $t = new \DateTimeImmutable('2026-06-06T14:32:11.123456-04:00');
        // 14:32:11 -04:00 == 18:32:11 UTC
        self::assertSame('2026-06-06 18:32:11.123456', Timestamp::format($t));
    }

    public function test_format_accepts_datetime_interface(): void
    {
        $t = new \DateTime('2026-06-06T14:32:11.123456Z');
        self::assertSame('2026-06-06 14:32:11.123456', Timestamp::format($t));
    }

    public function test_from_envelope_ts_parses_iso8601_with_milliseconds(): void
    {
        // EvidenceEnvelope.ts is formatted as Y-m-d\TH:i:s.v\Z in core.
        $iso = '2026-06-06T14:32:11.123Z';
        // .v is milliseconds; the helper should pad to microseconds.
        self::assertSame('2026-06-06 14:32:11.123000', Timestamp::fromEnvelopeTs($iso));
    }

    public function test_format_matches_canonical_constant(): void
    {
        self::assertSame('Y-m-d H:i:s.u', Timestamp::FORMAT);
    }
}
