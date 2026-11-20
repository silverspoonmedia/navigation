<?php

namespace App\Domain\Navigation\Tests;

use App\Domain\Navigation\NavigationServiceProvider;
use App\Support\Testing\PackageTestCase;

/**
 * Navigation's standalone harness.
 *
 * Boots the kernel plus this package's own provider, and builds the schema from
 * the migrations this package ships — nothing from the monolith's
 * `database/migrations` or `bootstrap/app.php`. A test that needs an HTTP route
 * the host registers belongs in `tests/Domain/Core/Navigation/`, not here.
 */
abstract class TestCase extends PackageTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), NavigationServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->runPackageMigrations(__DIR__.'/../database/migrations');
    }

    protected function truncateTables(string ...$tables): void
    {
        $connection = $this->app['db']->connection();

        foreach ($tables as $table) {
            $connection->table($table)->delete();
        }
    }
}
