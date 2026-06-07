<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

use Fissible\Attest\Headers\BitcoinCoreRpcHeaderProvider;
use Fissible\Attest\Headers\EsploraHeaderProvider;
use Fissible\Attest\Headers\HeaderProviderSet;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class HeaderProviderResolver
{
    /** @var (callable(): array{0:ClientInterface,1:RequestFactoryInterface,2:StreamFactoryInterface})|null */
    private $httpStackFactory;

    /**
     * @param (callable(): array{0:ClientInterface,1:RequestFactoryInterface,2:StreamFactoryInterface})|null $httpStackFactory
     */
    public function __construct(
        private readonly ConfigRepository $config,
        ?callable $httpStackFactory = null,
        private readonly ?bool $httpStackAvailable = null,
    ) {
        $this->httpStackFactory = $httpStackFactory;
    }

    public function resolve(
        ?string $bitcoinCoreRpc = null,
        ?string $bitcoinCoreCookie = null,
        ?string $esploraUrl = null,
    ): HeaderProviderSet {
        $bitcoinCoreRpc = $this->optionOrConfig($bitcoinCoreRpc, 'attest.headers.bitcoin_core_rpc');
        $bitcoinCoreCookie = $this->optionOrConfig($bitcoinCoreCookie, 'attest.headers.bitcoin_core_cookie');
        $esploraUrl = $this->optionOrConfig($esploraUrl, 'attest.headers.esplora_url');

        $providers = [];

        if ($bitcoinCoreRpc !== null) {
            [$http, $requests, $streams] = $this->httpStack('Bitcoin Core RPC');
            $providers[] = new BitcoinCoreRpcHeaderProvider(
                http: $http,
                requests: $requests,
                streams: $streams,
                rpcUrl: $bitcoinCoreRpc,
                cookieFile: $bitcoinCoreCookie,
            );
        }

        if ($esploraUrl !== null) {
            [$http, $requests] = $this->httpStack('Esplora');
            $providers[] = new EsploraHeaderProvider(
                http: $http,
                requests: $requests,
                baseUrl: $esploraUrl,
            );
        }

        return new HeaderProviderSet(...$providers);
    }

    private function optionOrConfig(?string $option, string $configKey): ?string
    {
        if ($option !== null) {
            return $this->normalizeNullableString($option);
        }

        $configured = $this->config->get($configKey);
        if (! is_string($configured)) {
            return null;
        }

        return $this->normalizeNullableString($configured);
    }

    private function normalizeNullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{0:ClientInterface,1:RequestFactoryInterface,2:StreamFactoryInterface}
     */
    private function httpStack(string $providerLabel): array
    {
        $factory = $this->httpStackFactory;
        if ($factory !== null) {
            $stack = $factory();
            if (! is_array($stack) || ! isset($stack[0], $stack[1], $stack[2])) {
                throw new \RuntimeException('HTTP stack factory must return [ClientInterface, RequestFactoryInterface, StreamFactoryInterface].');
            }
            [$http, $requests, $streams] = $stack;
            if (! $http instanceof ClientInterface
                || ! $requests instanceof RequestFactoryInterface
                || ! $streams instanceof StreamFactoryInterface
            ) {
                throw new \RuntimeException('HTTP stack factory must return [ClientInterface, RequestFactoryInterface, StreamFactoryInterface].');
            }

            return [$http, $requests, $streams];
        }

        if (! $this->hasOptionalHttpStack()) {
            throw new \RuntimeException(
                $providerLabel . ' header provider requires guzzlehttp/guzzle and guzzlehttp/psr7. '
                . 'Install them with: composer require guzzlehttp/guzzle guzzlehttp/psr7',
            );
        }

        $http = new \GuzzleHttp\Client();
        $factory = new \GuzzleHttp\Psr7\HttpFactory();

        return [$http, $factory, $factory];
    }

    private function hasOptionalHttpStack(): bool
    {
        if ($this->httpStackAvailable !== null) {
            return $this->httpStackAvailable;
        }

        return class_exists(\GuzzleHttp\Client::class)
            && class_exists(\GuzzleHttp\Psr7\HttpFactory::class);
    }
}
