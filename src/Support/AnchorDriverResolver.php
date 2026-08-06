<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

use Fissible\Attest\Anchor\AnchorDriver;
use Fissible\Attest\Anchor\NullDriver;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCalendarClient;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * @internal
 */
final class AnchorDriverResolver
{
    /** @var (callable(): OpenTimestampsCalendarClient)|null */
    private $calendarClientFactory;

    /**
     * @param (callable(): OpenTimestampsCalendarClient)|null $calendarClientFactory
     */
    public function __construct(
        private readonly ConfigRepository $config,
        ?callable $calendarClientFactory = null,
    ) {
        $this->calendarClientFactory = $calendarClientFactory;
    }

    /**
     * @param list<string> $calendarUrls
     */
    public function resolve(?string $driverName = null, array $calendarUrls = [], ?int $minCalendars = null): AnchorDriver
    {
        $name = $this->normalizeDriverName($driverName);

        return match ($name) {
            NullDriver::NAME => new NullDriver(),
            OpenTimestampsDriver::NAME => $this->openTimestampsDriver($calendarUrls, $minCalendars),
            default => throw new \InvalidArgumentException("Unknown attest anchor driver: {$name}"),
        };
    }

    /**
     * @return list<AnchorDriver>
     */
    public function verificationDrivers(): array
    {
        $drivers = [new NullDriver()];

        if ($this->calendarClientFactory !== null || $this->hasOptionalHttpStack()) {
            $drivers[] = $this->openTimestampsDriver([], null);
        }

        return $drivers;
    }

    /**
     * @param list<string> $calendarUrls
     */
    private function openTimestampsDriver(array $calendarUrls, ?int $minCalendars): OpenTimestampsDriver
    {
        $urls = $this->normalizeCalendarUrls($calendarUrls);
        if ($urls === []) {
            $urls = $this->configuredCalendarUrls();
        }

        $min = $minCalendars ?? $this->configuredMinCalendars();
        $client = $this->calendarClient();

        if ($urls === []) {
            return new OpenTimestampsDriver($client, minCalendars: $min);
        }

        return new OpenTimestampsDriver(
            calendarClient: $client,
            calendarUrls: $urls,
            minCalendars: $min,
            upgradeAllowlist: $urls,
        );
    }

    private function normalizeDriverName(?string $driverName): string
    {
        $value = $driverName;
        if ($value === null || trim($value) === '') {
            $configured = $this->config->get('attest.anchoring.default_driver', NullDriver::NAME);
            $value = is_string($configured) && trim($configured) !== ''
                ? $configured
                : NullDriver::NAME;
        }

        return strtolower(trim($value));
    }

    /**
     * @return list<string>
     */
    private function configuredCalendarUrls(): array
    {
        $configured = $this->config->get('attest.anchoring.calendars', []);
        if (is_string($configured)) {
            return $this->normalizeCalendarUrls(explode(',', $configured));
        }

        if (! is_array($configured)) {
            return [];
        }

        return $this->normalizeCalendarUrls($configured);
    }

    private function configuredMinCalendars(): int
    {
        $configured = $this->config->get('attest.anchoring.min_calendars', 1);
        if (is_int($configured)) {
            return $configured;
        }

        if (is_numeric($configured)) {
            return (int) $configured;
        }

        return 1;
    }

    /**
     * @param array<mixed> $calendarUrls
     * @return list<string>
     */
    private function normalizeCalendarUrls(array $calendarUrls): array
    {
        $normalized = [];
        foreach ($calendarUrls as $url) {
            if (! is_string($url)) {
                continue;
            }
            $url = trim($url);
            if ($url !== '') {
                $normalized[] = $url;
            }
        }

        return $normalized;
    }

    private function calendarClient(): OpenTimestampsCalendarClient
    {
        $factory = $this->calendarClientFactory;
        if ($factory !== null) {
            $client = $factory();
            if (! $client instanceof OpenTimestampsCalendarClient) {
                throw new \RuntimeException('OpenTimestamps calendar client factory must return OpenTimestampsCalendarClient.');
            }

            return $client;
        }

        if (! $this->hasOptionalHttpStack()) {
            throw new \RuntimeException(
                'OpenTimestamps support requires guzzlehttp/guzzle and guzzlehttp/psr7. '
                . 'Install them with: composer require guzzlehttp/guzzle guzzlehttp/psr7',
            );
        }

        return OpenTimestampsCalendarClient::withGuzzle();
    }

    private function hasOptionalHttpStack(): bool
    {
        return class_exists(\GuzzleHttp\Client::class)
            && class_exists(\GuzzleHttp\Psr7\HttpFactory::class);
    }
}
