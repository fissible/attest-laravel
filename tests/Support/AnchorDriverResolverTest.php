<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Support;

use Fissible\Attest\Anchor\NullDriver;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCalendarClient;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Fissible\AttestLaravel\Support\AnchorDriverResolver;
use Fissible\AttestLaravel\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;

final class AnchorDriverResolverTest extends TestCase
{
    public function test_resolves_local_only_driver(): void
    {
        $resolver = new AnchorDriverResolver(new Repository([]));

        $driver = $resolver->resolve('local-only');

        self::assertInstanceOf(NullDriver::class, $driver);
        self::assertSame(NullDriver::NAME, $driver->name());
    }

    public function test_resolves_opentimestamps_driver_with_injected_calendar_client(): void
    {
        $client = $this->calendarClient();
        $resolver = new AnchorDriverResolver(
            new Repository([
                'attest' => [
                    'anchoring' => [
                        'calendars' => ['https://calendar-a.example', ' https://calendar-b.example '],
                        'min_calendars' => '2',
                    ],
                ],
            ]),
            static fn (): OpenTimestampsCalendarClient => $client,
        );

        $driver = $resolver->resolve('opentimestamps');

        self::assertInstanceOf(OpenTimestampsDriver::class, $driver);
        self::assertSame(OpenTimestampsDriver::NAME, $driver->name());
        self::assertSame($client, $this->driverProperty($driver, 'calendarClient'));
        self::assertSame(['https://calendar-a.example', 'https://calendar-b.example'], $this->driverProperty($driver, 'calendarUrls'));
        self::assertSame(2, $this->driverProperty($driver, 'minCalendars'));
        self::assertSame(['https://calendar-a.example', 'https://calendar-b.example'], $this->driverProperty($driver, 'upgradeAllowlist'));
    }

    public function test_rejects_unknown_driver(): void
    {
        $resolver = new AnchorDriverResolver(new Repository([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown attest anchor driver');

        $resolver->resolve('not-real');
    }

    public function test_verification_drivers_include_local_only_and_opentimestamps_when_factory_is_available(): void
    {
        $resolver = new AnchorDriverResolver(
            new Repository([]),
            fn (): OpenTimestampsCalendarClient => $this->calendarClient(),
        );

        $drivers = $resolver->verificationDrivers();

        self::assertCount(2, $drivers);
        self::assertInstanceOf(NullDriver::class, $drivers[0]);
        self::assertInstanceOf(OpenTimestampsDriver::class, $drivers[1]);
    }

    public function test_explicit_calendar_urls_override_config(): void
    {
        $resolver = new AnchorDriverResolver(
            new Repository([
                'attest' => [
                    'anchoring' => [
                        'calendars' => ['https://configured.example'],
                        'min_calendars' => 1,
                    ],
                ],
            ]),
            fn (): OpenTimestampsCalendarClient => $this->calendarClient(),
        );

        $driver = $resolver->resolve(
            'opentimestamps',
            [' https://explicit-a.example ', '', 'https://explicit-b.example'],
            2,
        );

        self::assertInstanceOf(OpenTimestampsDriver::class, $driver);
        self::assertSame(['https://explicit-a.example', 'https://explicit-b.example'], $this->driverProperty($driver, 'calendarUrls'));
        self::assertSame(['https://explicit-a.example', 'https://explicit-b.example'], $this->driverProperty($driver, 'upgradeAllowlist'));
        self::assertSame(2, $this->driverProperty($driver, 'minCalendars'));
    }

    public function test_service_provider_binds_resolver(): void
    {
        $resolver = $this->app->make(AnchorDriverResolver::class);

        self::assertInstanceOf(AnchorDriverResolver::class, $resolver);
    }

    private function calendarClient(): OpenTimestampsCalendarClient
    {
        $mock = new MockHandler([]);
        $stack = HandlerStack::create($mock);
        $http = new Client(['handler' => $stack]);
        $factory = new HttpFactory();

        return new OpenTimestampsCalendarClient($http, $factory, $factory);
    }

    /**
     * @return mixed
     */
    private function driverProperty(OpenTimestampsDriver $driver, string $property): mixed
    {
        $ref = new \ReflectionProperty($driver, $property);

        return $ref->getValue($driver);
    }
}
