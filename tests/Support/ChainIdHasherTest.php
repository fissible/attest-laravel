<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Support;

use Fissible\AttestLaravel\Support\ChainIdHasher;
use PHPUnit\Framework\TestCase;

final class ChainIdHasherTest extends TestCase
{
    public function test_hash_returns_32_char_lowercase_hex(): void
    {
        $hash = ChainIdHasher::hash('tenant:5');
        self::assertSame(32, strlen($hash));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $hash);
    }

    public function test_hash_is_deterministic(): void
    {
        self::assertSame(
            ChainIdHasher::hash('tenant:5'),
            ChainIdHasher::hash('tenant:5'),
        );
    }

    public function test_different_inputs_produce_different_hashes(): void
    {
        self::assertNotSame(
            ChainIdHasher::hash('tenant:5'),
            ChainIdHasher::hash('tenant:6'),
        );
    }

    public function test_hash_is_first_32_hex_chars_of_sha256(): void
    {
        $expected = substr(hash('sha256', 'tenant:5'), 0, 32);
        self::assertSame($expected, ChainIdHasher::hash('tenant:5'));
    }
}
