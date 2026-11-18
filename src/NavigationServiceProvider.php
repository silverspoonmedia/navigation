<?php

namespace App\Domain\Navigation;

use App\Domain\Identity\Models\Role;
use App\Domain\Navigation\Console\Commands\FlushMenuCache;
use App\Domain\Navigation\Listeners\ForgetPresentedNavigationTree;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Repositories\Contracts\MenuRepositoryInterface;
use App\Domain\Navigation\Repositories\Eloquent\MenuRepository;
use App\Support\ActivityLog\SubjectAliasRegistrar;
use App\Support\Navigation\PresentedTreeEpoch;
use App\Support\Product\Events\ProductActivationStateChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NavigationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/navigation.php', 'navigation');

        $this->app->bind(MenuRepositoryInterface::class, MenuRepository::class);

        // The kernel (DomainAccess) only needs the epoch contract; binding the
        // interface keeps the dependency pointing kernel -> package.
        $this->app->bind(PresentedTreeEpoch::class, MenuRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Plain (non-namespaced) lang path: FileLoader::loadPaths() resolves
        // "{path}/{locale}/{group}.php", so the path is the parent of the locale
        // directories. Keys stay `api/navigation.*` — no key moves.
        $this->app['translator']->getLoader()->addPath(__DIR__.'/../resources/lang');

        if ($this->app->runningInConsole()) {
            $this->commands([FlushMenuCache::class]);
        }

        // The navigation snapshot caches role names. Renaming or deleting a role
        // would otherwise leave the cached gate referencing a stale name.
        $flushMenuCache = fn () => $this->app->make(MenuRepositoryInterface::class)->flushNavigationCache();
        Role::updated($flushMenuCache);
        Role::deleted($flushMenuCache);

        Event::listen(ProductActivationStateChanged::class, ForgetPresentedNavigationTree::class);

        // The activity trail can filter by `?subject=menu`. That alias points at
        // a model this package owns, so it is declared here through the
        // kernel-owned contract — `silverspoonmedia/audit` never imports navigation.
        if ($this->app->bound(SubjectAliasRegistrar::class)) {
            $this->app->make(SubjectAliasRegistrar::class)->register('menu', Menu::class);
        }
    }
}
