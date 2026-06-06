<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests;

use Fissible\AttestLaravel\AttestServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return array<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AttestServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new \RuntimeException("Unknown DB_CONNECTION: $driver");
        }
        $app['config']->set('database.default', $driver);
        $app['config']->set("database.connections.$driver", $this->driverConfig($driver));
    }

    /** @return array<string,mixed> */
    private function driverConfig(string $driver): array
    {
        return match ($driver) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'foreign_key_constraints' => true,
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 3306),
                'database' => getenv('DB_DATABASE') ?: 'attest',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 5432),
                'database' => getenv('DB_DATABASE') ?: 'attest',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8',
            ],
        };
    }
}
