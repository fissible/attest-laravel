<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel;

use Illuminate\Support\ServiceProvider;

/**
 * Stub. Bindings, config publishing, and migration auto-loading land
 * in Task 4.13. Exists now so composer auto-discovery does not error
 * when the package is installed.
 */
final class AttestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
