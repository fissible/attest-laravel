<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\Signer;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Stores\EloquentAnchorClaimStore;
use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Stores\Locking\ChainLocker;
use Fissible\AttestLaravel\Stores\Locking\MysqlChainLocker;
use Fissible\AttestLaravel\Stores\Locking\PostgresChainLocker;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Support\AttestRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use ParagonIE\ConstantTime\Base64;

final class AttestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/attest.php', 'attest');
        $this->registerChunk5Services();

        $this->app->singleton(ChainLocker::class, function (Container $app): ChainLocker {
            $conn = $this->attestConnection($app);
            $driver = $conn->getDriverName();
            $config = $app->make(ConfigRepository::class);
            $timeout = (int) $config->get('attest.lock_timeout_seconds', 10);
            return match ($driver) {
                'sqlite' => new SqliteChainLocker($conn, $timeout),
                'mysql' => new MysqlChainLocker($conn, $timeout),
                'pgsql' => new PostgresChainLocker(
                    $conn,
                    $timeout,
                    (int) $config->get('attest.postgres_lock_poll_us', 50_000),
                ),
                default => throw new \RuntimeException("Unsupported DB driver for attest: $driver"),
            };
        });

        $this->app->singleton(ChainStore::class, function (Container $app): ChainStore {
            return new EloquentChainStore(
                $this->attestConnection($app),
                $app->make(ChainLocker::class),
                $app->make(Dispatcher::class),
            );
        });

        $this->app->singleton(AnchorClaimStore::class, function (Container $app): AnchorClaimStore {
            return new EloquentAnchorClaimStore($this->attestConnection($app));
        });

        $this->app->singleton(Signer::class, function (Container $app): Signer {
            $cfg = $app->make(ConfigRepository::class)->get('attest.signing_key');
            $seedEnv = is_array($cfg) ? ($cfg['seed_env'] ?? 'ATTEST_SIGNING_KEY_SEED') : 'ATTEST_SIGNING_KEY_SEED';
            $keyIdEnv = is_array($cfg) ? ($cfg['key_id_env'] ?? 'ATTEST_SIGNING_KEY_ID') : 'ATTEST_SIGNING_KEY_ID';
            $seedBase64 = getenv($seedEnv) ?: '';
            $keyId = getenv($keyIdEnv) ?: '';
            if ($seedBase64 === '' || $keyId === '') {
                throw new \RuntimeException(sprintf(
                    'Attest signer requires env vars %s and %s', $seedEnv, $keyIdEnv,
                ));
            }
            $seed = Base64::decode($seedBase64, strictPadding: true);
            return new SodiumSigner(KeyPair::fromSeed($seed), keyId: $keyId);
        });

        $this->app->singleton(AttestRegistry::class, function (Container $app): AttestRegistry {
            return new AttestRegistry(
                $app->make(ChainStore::class),
                $app->make(AnchorClaimStore::class),
                $app->make(Signer::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/attest.php' => $this->app->configPath('attest.php'),
        ], 'attest-config');
        $this->publishes([
            __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'attest-migrations');

        if ($this->app->runningInConsole()) {
            $commands = $this->chunk5Commands();
            if ($commands !== []) {
                $this->commands($commands);
            }
        }

        // Force UTC on the attest connection's DB session so the
        // Y-m-d H:i:s.u strings Timestamp emits are interpreted as UTC
        // on insert and selected as UTC on read. SQLite has no session
        // timezone; MySQL and Postgres do.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $config = $this->app->make(ConfigRepository::class);
            $attestName = $config->get('attest.connection')
                ?? $config->get('database.default');
            if ($event->connectionName !== $attestName) {
                return;
            }
            $this->forceUtc($event->connection);
        });
    }

    private function attestConnection(Container $app): Connection
    {
        $config = $app->make(ConfigRepository::class);
        $name = $config->get('attest.connection') ?? $config->get('database.default');
        $conn = $app->make(DatabaseManager::class)->connection(is_string($name) ? $name : null);
        assert($conn instanceof Connection);
        $this->forceUtc($conn);
        return $conn;
    }

    private function forceUtc(Connection $conn): void
    {
        switch ($conn->getDriverName()) {
            case 'mysql':
                $conn->statement("SET time_zone = '+00:00'");
                break;
            case 'pgsql':
                $conn->statement("SET TIME ZONE 'UTC'");
                break;
            // SQLite stores naive strings; no session timezone applies.
        }
    }

    private function registerChunk5Services(): void
    {
        $services = [
            Support\AnchorDriverResolver::class,
            Support\HeaderProviderResolver::class,
            Support\TrustedKeyResolver::class,
            Services\AnchorRangeRunner::class,
            Services\UpgradePendingAnchors::class,
            Services\VerifyChain::class,
            Services\BundleOperations::class,
            'Fissible\\AttestLaravel\\Services\\IntegrityAudit',
        ];

        foreach ($services as $service) {
            if (class_exists($service)) {
                $this->app->singleton($service);
            }
        }
    }

    /** @return list<class-string> */
    private function chunk5Commands(): array
    {
        $commands = [
            'Fissible\\AttestLaravel\\Console\\Commands\\AnchorCommand',
            'Fissible\\AttestLaravel\\Console\\Commands\\UpgradeCommand',
            'Fissible\\AttestLaravel\\Console\\Commands\\VerifyCommand',
            'Fissible\\AttestLaravel\\Console\\Commands\\BundleExportCommand',
            'Fissible\\AttestLaravel\\Console\\Commands\\BundleVerifyCommand',
            'Fissible\\AttestLaravel\\Console\\Commands\\IntegrityAuditCommand',
        ];

        $existing = [];
        foreach ($commands as $command) {
            if (class_exists($command)) {
                $existing[] = $command;
            }
        }

        return $existing;
    }
}
