<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Support;

use Fissible\Attest\Headers\BitcoinCoreRpcHeaderProvider;
use Fissible\Attest\Headers\EsploraHeaderProvider;
use Fissible\Attest\Headers\TrustLevel;
use Fissible\AttestLaravel\Support\HeaderProviderResolver;
use Fissible\AttestLaravel\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;

final class HeaderProviderResolverTest extends TestCase
{
    public function test_returns_empty_set_when_no_header_providers_are_configured(): void
    {
        $resolver = new HeaderProviderResolver(new Repository([]));

        $headers = $resolver->resolve();

        self::assertCount(0, $headers);
        self::assertSame([], $headers->names());
    }

    public function test_resolves_configured_bitcoin_core_and_esplora_providers(): void
    {
        $resolver = new HeaderProviderResolver(
            new Repository([
                'attest' => [
                    'headers' => [
                        'bitcoin_core_rpc' => ' http://user:pass@127.0.0.1:8332/ ',
                        'esplora_url' => ' https://blockstream.info/api/ ',
                    ],
                ],
            ]),
            $this->httpStackFactory(),
        );

        $headers = $resolver->resolve();

        self::assertCount(2, $headers);
        self::assertSame(['bitcoin-core-rpc', 'esplora'], $headers->names());
        self::assertSame(TrustLevel::LOCAL, $headers->trustLevelsByName()['bitcoin-core-rpc']);
        self::assertSame(TrustLevel::REMOTE, $headers->trustLevelsByName()['esplora']);
        self::assertInstanceOf(BitcoinCoreRpcHeaderProvider::class, $headers->providers()[0]);
        self::assertInstanceOf(EsploraHeaderProvider::class, $headers->providers()[1]);
        self::assertSame('http://127.0.0.1:8332/', $this->providerProperty($headers->providers()[0], 'rpcUrl'));
        self::assertSame('https://blockstream.info/api', $this->providerProperty($headers->providers()[1], 'baseUrl'));
    }

    public function test_explicit_options_override_configured_provider_urls(): void
    {
        $resolver = new HeaderProviderResolver(
            new Repository([
                'attest' => [
                    'headers' => [
                        'bitcoin_core_rpc' => 'http://configured.example:8332/',
                        'esplora_url' => 'https://configured.example/api',
                    ],
                ],
            ]),
            $this->httpStackFactory(),
        );

        $headers = $resolver->resolve(
            bitcoinCoreRpc: ' http://explicit.example:8332/ ',
            esploraUrl: ' https://explicit.example/api/ ',
        );

        self::assertCount(2, $headers);
        self::assertSame('http://explicit.example:8332/', $this->providerProperty($headers->providers()[0], 'rpcUrl'));
        self::assertSame('https://explicit.example/api', $this->providerProperty($headers->providers()[1], 'baseUrl'));
    }

    public function test_empty_explicit_option_disables_configured_provider(): void
    {
        $resolver = new HeaderProviderResolver(
            new Repository([
                'attest' => [
                    'headers' => [
                        'bitcoin_core_rpc' => 'http://configured.example:8332/',
                        'esplora_url' => 'https://configured.example/api',
                    ],
                ],
            ]),
            $this->httpStackFactory(),
        );

        $headers = $resolver->resolve(bitcoinCoreRpc: '', esploraUrl: '');

        self::assertCount(0, $headers);
    }

    public function test_provider_url_without_optional_http_stack_throws_install_hint(): void
    {
        $resolver = new HeaderProviderResolver(new Repository([]), httpStackAvailable: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('guzzlehttp/guzzle');
        $this->expectExceptionMessage('guzzlehttp/psr7');

        $resolver->resolve(bitcoinCoreRpc: 'http://127.0.0.1:8332/');
    }

    public function test_service_provider_binds_resolver(): void
    {
        $resolver = $this->app->make(HeaderProviderResolver::class);

        self::assertInstanceOf(HeaderProviderResolver::class, $resolver);
    }

    /**
     * @return callable(): array{0:\Psr\Http\Client\ClientInterface,1:\Psr\Http\Message\RequestFactoryInterface,2:\Psr\Http\Message\StreamFactoryInterface}
     */
    private function httpStackFactory(): callable
    {
        return static function (): array {
            $http = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);
            $factory = new HttpFactory();

            return [$http, $factory, $factory];
        };
    }

    private function providerProperty(object $provider, string $property): mixed
    {
        $ref = new \ReflectionProperty($provider, $property);

        return $ref->getValue($provider);
    }
}
