<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Support;

use Fissible\AttestLaravel\Support\TrustedKeyResolver;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Config\Repository;
use ParagonIE\ConstantTime\Base64;

final class TrustedKeyResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/attest-laravel-trusted-keys-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_resolves_valid_inline_key(): void
    {
        $publicKey = str_repeat('a', 32);
        $resolver = new TrustedKeyResolver(new Repository([]));

        $keys = $resolver->resolve(inline: ['issuer=' . Base64::encode($publicKey)]);

        self::assertCount(1, $keys);
        self::assertSame($publicKey, $keys[0]->publicKey);
        self::assertSame('issuer', $keys[0]->keyId);
        self::assertNotSame('', $keys[0]->fingerprint);
    }

    public function test_resolves_valid_file_key_without_key_id(): void
    {
        $publicKey = str_repeat('b', 32);
        $path = $this->writeKeyFile('bare.pub', $publicKey);
        $resolver = new TrustedKeyResolver(new Repository([]));

        $keys = $resolver->resolve(files: [$path]);

        self::assertCount(1, $keys);
        self::assertSame($publicKey, $keys[0]->publicKey);
        self::assertNull($keys[0]->keyId);
    }

    public function test_merges_configured_and_explicit_keys_in_order(): void
    {
        $configInline = str_repeat('c', 32);
        $explicitInline = str_repeat('d', 32);
        $configFileKey = str_repeat('e', 32);
        $explicitFileKey = str_repeat('f', 32);
        $configPath = $this->writeKeyFile('config.pub', $configFileKey);
        $explicitPath = $this->writeKeyFile('explicit.pub', $explicitFileKey);

        $resolver = new TrustedKeyResolver(new Repository([
            'attest' => [
                'verification' => [
                    'trusted_keys' => ['config=' . Base64::encode($configInline)],
                    'trusted_key_files' => [$configPath],
                ],
            ],
        ]));

        $keys = $resolver->resolve(
            inline: ['explicit=' . Base64::encode($explicitInline)],
            files: [$explicitPath],
        );

        self::assertCount(4, $keys);
        self::assertSame('config', $keys[0]->keyId);
        self::assertSame('explicit', $keys[1]->keyId);
        self::assertNull($keys[2]->keyId);
        self::assertNull($keys[3]->keyId);
        self::assertSame($configInline, $keys[0]->publicKey);
        self::assertSame($explicitInline, $keys[1]->publicKey);
        self::assertSame($configFileKey, $keys[2]->publicKey);
        self::assertSame($explicitFileKey, $keys[3]->publicKey);
    }

    public function test_rejects_invalid_base64_inline_key(): void
    {
        $resolver = new TrustedKeyResolver(new Repository([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('strict base64');

        $resolver->resolve(inline: ['issuer=not valid base64']);
    }

    public function test_rejects_wrong_public_key_length(): void
    {
        $resolver = new TrustedKeyResolver(new Repository([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('32 bytes');

        $resolver->resolve(inline: ['issuer=' . Base64::encode('too-short')]);
    }

    public function test_rejects_missing_file(): void
    {
        $resolver = new TrustedKeyResolver(new Repository([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trusted-key file not found');

        $resolver->resolve(files: [$this->tmpDir . '/missing.pub']);
    }

    public function test_resolves_file_key_with_explicit_key_id(): void
    {
        $publicKey = str_repeat('g', 32);
        $path = $this->writeKeyFile('issuer.pub', $publicKey);
        $resolver = new TrustedKeyResolver(new Repository([]));

        $keys = $resolver->resolve(files: ['issuer=' . $path]);

        self::assertCount(1, $keys);
        self::assertSame($publicKey, $keys[0]->publicKey);
        self::assertSame('issuer', $keys[0]->keyId);
    }

    public function test_service_provider_binds_resolver(): void
    {
        $resolver = $this->app->make(TrustedKeyResolver::class);

        self::assertInstanceOf(TrustedKeyResolver::class, $resolver);
    }

    private function writeKeyFile(string $name, string $publicKey): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, Base64::encode($publicKey) . "\n");

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }

        rmdir($path);
    }
}
