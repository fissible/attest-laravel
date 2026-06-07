<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

use Fissible\Attest\Verification\TrustedKey;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ParagonIE\ConstantTime\Base64;

final class TrustedKeyResolver
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * @param list<string> $inline
     * @param list<string> $files
     * @return list<TrustedKey>
     */
    public function resolve(array $inline = [], array $files = []): array
    {
        $trustedKeys = [];

        foreach ($this->mergeConfiguredAndExplicit('attest.verification.trusted_keys', $inline) as $entry) {
            $trustedKeys[] = $this->trustedInlineKey($entry);
        }

        foreach ($this->mergeConfiguredAndExplicit('attest.verification.trusted_key_files', $files) as $entry) {
            $trustedKeys[] = $this->trustedFileKey($entry);
        }

        return $trustedKeys;
    }

    private function trustedInlineKey(string $entry): TrustedKey
    {
        if (! str_contains($entry, '=')) {
            throw new \InvalidArgumentException("Invalid trusted key entry: {$entry} (expected '<key_id>=<base64-pubkey>')");
        }

        [$keyId, $base64] = explode('=', $entry, 2);
        $keyId = trim($keyId);
        if ($keyId === '') {
            throw new \InvalidArgumentException('Trusted key key_id must not be empty');
        }

        return new TrustedKey($this->decodePublicKey($base64), keyId: $keyId);
    }

    private function trustedFileKey(string $entry): TrustedKey
    {
        [$keyId, $path] = $this->parseFileEntry($entry);

        if (! is_file($path)) {
            throw new \InvalidArgumentException("Trusted-key file not found: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException("Could not read trusted-key file: {$path}");
        }

        return new TrustedKey($this->decodePublicKey(trim($contents)), keyId: $keyId);
    }

    /**
     * @return array{0:?string,1:string}
     */
    private function parseFileEntry(string $entry): array
    {
        if (str_contains($entry, '=')) {
            [$keyId, $path] = explode('=', $entry, 2);
            $keyId = trim($keyId);
            $path = trim($path);
            if ($keyId === '') {
                throw new \InvalidArgumentException('Trusted-key file key_id must not be empty');
            }
            if ($path === '') {
                throw new \InvalidArgumentException('Trusted-key file path must not be empty');
            }

            return [$keyId, $path];
        }

        $path = trim($entry);
        if ($path === '') {
            throw new \InvalidArgumentException('Trusted-key file path must not be empty');
        }

        return [null, $path];
    }

    private function decodePublicKey(string $base64): string
    {
        try {
            $raw = Base64::decode(trim($base64), strictPadding: true);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Trusted public key must be strict base64', previous: $e);
        }

        if (strlen($raw) !== 32) {
            throw new \InvalidArgumentException('Trusted public key must decode to 32 bytes');
        }

        return $raw;
    }

    /**
     * @param list<string> $explicit
     * @return list<string>
     */
    private function mergeConfiguredAndExplicit(string $configKey, array $explicit): array
    {
        return [
            ...$this->configuredList($configKey),
            ...$this->normalizeList($explicit),
        ];
    }

    /**
     * @return list<string>
     */
    private function configuredList(string $configKey): array
    {
        $configured = $this->config->get($configKey, []);
        if (is_string($configured)) {
            return $this->normalizeList([$configured]);
        }

        if (! is_array($configured)) {
            return [];
        }

        return $this->normalizeList($configured);
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function normalizeList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }
}
