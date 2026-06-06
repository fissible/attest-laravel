<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

final class Timestamp
{
    /** Canonical wire format used for every attest write. UTC,
     *  microsecond precision, dialect-portable when bound as a string
     *  rather than as a DateTimeInterface (Laravel's grammar drops
     *  fractional seconds). */
    public const FORMAT = 'Y-m-d H:i:s.u';

    public static function format(\DateTimeInterface $when): string
    {
        $utc = $when instanceof \DateTimeImmutable
            ? $when->setTimezone(new \DateTimeZone('UTC'))
            : \DateTimeImmutable::createFromInterface($when)->setTimezone(new \DateTimeZone('UTC'));
        return $utc->format(self::FORMAT);
    }

    public static function fromEnvelopeTs(string $iso8601): string
    {
        return self::format(new \DateTimeImmutable($iso8601));
    }
}
