<?php

namespace App\Domain\Navigation\Database\Seeders;

use App\Domain\Identity\Models\Role;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Repositories\Contracts\MenuRepositoryInterface;
use App\Domain\Navigation\Support\MenuLayout;
use App\Support\Navigation\MenuContributorRegistry;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rebuilds the canonical dynamic navigation tree.
 *
 * This seeder is intentionally authoritative: every run replaces all active and
 * trashed menu rows so existing installations converge on the same UX tree.
 *
 * The tree's **shape** is the package's (workspace / products / administration);
 * the nodes a product owns arrive as {@see MenuContributor} registrations and are
 * merged by node key. With no product installed the same code seeds the core tree
 * and an empty group is dropped, so the package names no product.
 */
class MenuSeeder extends Seeder
{
    use WithoutModelEvents;

    /** @var array<string, string> */
    private array $roleIds = [];

    public function run(): void
    {
        $this->roleIds = Role::query()
            ->where('guard_name', 'api')
            ->pluck('id', 'name')
            ->all();

        $contributions = $this->contributions();

        DB::transaction(function () use ($contributions): void {
            Menu::withTrashed()->forceDelete();

            $this->seedTree($this->mergeContributions($this->sidebarTree(), $contributions), MenuLayout::SIDEBAR);
            $this->seedTree($this->mergeContributions($this->navbarTree(), $contributions), MenuLayout::NAVBAR);
        });

        app(MenuRepositoryInterface::class)->flushNavigationCache();
    }

    /**
     * Contributions grouped by target node key, or an empty map on a bare host.
     *
     * @return array<string, list<array{nodes: list<array<string, mixed>>, after: string|null}>>
     */
    private function contributions(): array
    {
        if (! app()->bound(MenuContributorRegistry::class)) {
            return [];
        }

        return app(MenuContributorRegistry::class)->contributions();
    }

    /**
     * Insert every contribution under the node its target key names.
     *
     * A contribution lands after the sibling key its `after` names — including a
     * node another contribution just inserted — or at the end of the group.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, list<array{nodes: list<array<string, mixed>>, after: string|null}>>  $contributions
     * @return list<array<string, mixed>>
     */
    private function mergeContributions(array $nodes, array $contributions, string $prefix = ''): array
    {
        $merged = [];

        foreach ($nodes as $node) {
            $path = $this->nodePath($prefix, $node);
            $children = $node['children'] ?? [];

            if ($children !== []) {
                $node['children'] = $this->mergeContributions($children, $contributions, $path ?? '');
            }

            if ($path !== null && isset($contributions[$path])) {
                $node['children'] = $this->applyContribution($node['children'] ?? [], $contributions[$path]);
            }

            $merged[] = $node;
        }

        // A group whose only purpose was product placement collapses to nothing.
        return array_values(array_filter(
            $merged,
            fn (array $node): bool => ! ($node['drop_when_empty'] ?? false) || ($node['children'] ?? []) !== [],
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @param  list<array{nodes: list<array<string, mixed>>, after: string|null}>  $entries
     * @return list<array<string, mixed>>
     */
    private function applyContribution(array $children, array $entries): array
    {
        foreach ($entries as $entry) {
            $anchorKey = $entry['after'];

            if ($anchorKey === null) {
                $children = [...$children, ...$entry['nodes']];

                continue;
            }

            $position = $this->indexOfKey($children, $anchorKey);

            if ($position === null) {
                $children = [...$children, ...$entry['nodes']];

                continue;
            }

            array_splice($children, $position + 1, 0, $entry['nodes']);
        }

        return $children;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function indexOfKey(array $nodes, string $key): ?int
    {
        foreach ($nodes as $index => $node) {
            if (($node['key'] ?? null) === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodePath(string $prefix, array $node): ?string
    {
        $key = $node['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        return $prefix === '' ? $key : $prefix.'.'.$key;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function seedTree(array $nodes, string $layout, ?string $parentId = null): void
    {
        foreach (array_values($nodes) as $index => $node) {
            $children = $node['children'] ?? [];
            $roleNames = $node['roles'] ?? [];

            $menu = Menu::query()->create([
                'parent_id' => $parentId,
                'layout' => $layout,
                'name' => $node['name'],
                'description' => $node['description'] ?? null,
                'icon' => $node['icon'] ?? null,
                'route' => $node['route'] ?? null,
                'hash' => $node['hash'] ?? null,
                'type' => $node['type'],
                'order' => $index + 1,
                // The audience is derived from the declared roles so a seeded
                // node can never carry role pivots under a non-role audience.
                'audience' => $node['audience'] ?? ($roleNames === [] ? MenuAudience::Auth : MenuAudience::Role),
            ]);

            if ($roleNames !== []) {
                $menu->roles()->sync($this->idsForRoles($roleNames));
            }

            if ($children !== []) {
                $this->seedTree($children, $layout, $menu->id);
            }
        }
    }

    /**
     * @param  list<string>  $roleNames
     * @return list<string>
     */
    private function idsForRoles(array $roleNames): array
    {
        $missing = array_values(array_filter(
            $roleNames,
            fn (string $roleName): bool => ! isset($this->roleIds[$roleName]),
        ));

        if ($missing !== []) {
            throw new RuntimeException(
                'MenuSeeder references unseeded role(s): '.implode(', ', array_unique($missing))
                .'. Seed RoleSeeder and the product role seeders (e.g. the product that owns the contributed node) before MenuSeeder.'
            );
        }

        return array_values(array_map(
            fn (string $roleName): string => $this->roleIds[$roleName],
            $roleNames,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sidebarTree(): array
    {
        $managementRoles = ['Root', 'Admin', 'Moderator', 'Management'];
        $adminRoles = ['Root', 'Admin'];

        return [
            [
                'key' => 'workspace',
                'name' => $this->translations('Workspace', 'Ruang Kerja'),
                'type' => 'h',
                'children' => [
                    [
                        'key' => 'workspace.dashboard',
                        'name' => $this->translations('Dashboard', 'Beranda'),
                        'icon' => 'bi bi-speedometer2',
                        'route' => 'dashboard',
                        'type' => 'p',
                    ],
                    [
                        'key' => 'workspace.notifications',
                        'name' => $this->translations('Notifications', 'Notifikasi'),
                        'icon' => 'bi bi-bell',
                        'route' => 'notifications.index',
                        'type' => 'p',
                    ],
                    [
                        'key' => 'workspace.services',
                        'name' => $this->translations('Services', 'Layanan'),
                        'icon' => 'bi bi-box-seam',
                        'route' => 'services.index',
                        'type' => 'p',
                    ],
                ],
            ],
            [
                // Products is a placement group: a product domain contributes its
                // nodes here (see MenuContributor); with none installed it drops.
                'key' => 'products',
                'name' => $this->translations('Products', 'Produk'),
                'type' => 'h',
                'drop_when_empty' => true,
                'children' => [],
            ],
            [
                'key' => 'administration',
                'name' => $this->translations('Administration', 'Administrasi'),
                'type' => 'h',
                'children' => [
                    [
                        'key' => 'administration.access-control',
                        'name' => $this->translations('Access control', 'Kontrol akses'),
                        'icon' => 'bi bi-people',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'key' => 'administration.access-control.users',
                                'name' => $this->translations('User management', 'Manajemen Pengguna'),
                                'icon' => 'bi bi-people',
                                'route' => 'users.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.access-control.activations',
                                'name' => $this->translations('Product activations', 'Aktivasi Produk'),
                                'icon' => 'bi bi-toggle-on',
                                'route' => 'product-activations.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.access-control.domain-access',
                                'name' => $this->translations('Product domain access', 'Akses Domain Produk'),
                                'icon' => 'bi bi-shield-lock',
                                'route' => 'domain-access.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'administration.payments',
                        'name' => $this->translations('Payments', 'Pembayaran'),
                        'icon' => 'bi bi-credit-card',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'key' => 'administration.payments.income',
                                'name' => $this->translations('Payment income', 'Pendapatan pembayaran'),
                                'icon' => 'bi bi-graph-up-arrow',
                                'route' => 'payments.income.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.payments.invoices',
                                'name' => $this->translations('Invoices', 'Invoice'),
                                'icon' => 'bi bi-receipt',
                                'route' => 'invoices.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.payments.subscriptions',
                                'name' => $this->translations('Subscriptions', 'Langganan'),
                                'icon' => 'bi bi-arrow-repeat',
                                'route' => 'subscriptions.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.payments.channels',
                                'name' => $this->translations('Payment channels', 'Channel pembayaran'),
                                'icon' => 'bi bi-credit-card-2-front',
                                'route' => 'payment-channels.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.payments.gateways',
                                'name' => $this->translations('Payment gateways', 'Payment gateway'),
                                'icon' => 'bi bi-diagram-3',
                                'route' => 'payment-gateways.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'administration.inference',
                        'name' => $this->translations('Inference', 'Inferensi'),
                        'icon' => 'bi bi-cpu',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'key' => 'administration.inference.model-cards',
                                'name' => $this->translations('Model catalog', 'Katalog model'),
                                'icon' => 'bi bi-boxes',
                                'route' => 'inference.model-cards.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.inference.providers',
                                'name' => $this->translations('Providers', 'Provider'),
                                'icon' => 'bi bi-hdd-network',
                                'route' => 'inference.providers.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.inference.usage-events',
                                'name' => $this->translations('Usage events', 'Event penggunaan'),
                                'icon' => 'bi bi-graph-up',
                                'route' => 'inference.usage-events.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'administration.ai-connections',
                        'name' => $this->translations('AI connections', 'Koneksi AI'),
                        'icon' => 'bi bi-plug',
                        'route' => 'ai.connections.index',
                        'type' => 'p',
                        'roles' => $managementRoles,
                    ],
                    [
                        'key' => 'administration.monitoring',
                        'name' => $this->translations('Monitoring', 'Pemantauan'),
                        'icon' => 'bi bi-activity',
                        'type' => 'p',
                        'roles' => $adminRoles,
                        'children' => [
                            [
                                'key' => 'administration.monitoring.activity-log',
                                'name' => $this->translations('Activity log', 'Log Aktivitas'),
                                'icon' => 'bi bi-journal-text',
                                'route' => 'activity-logs.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'key' => 'administration.monitoring.error-log',
                                'name' => $this->translations('Error log', 'Log Error'),
                                'icon' => 'bi bi-bug',
                                'route' => 'error-logs.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'settings',
                        'name' => $this->translations('Settings', 'Pengaturan'),
                        'icon' => 'bi bi-gear',
                        'type' => 'p',
                        'roles' => $adminRoles,
                        'children' => [
                            [
                                'key' => 'settings.platform',
                                'name' => $this->translations('Platform', 'Platform'),
                                'icon' => 'bi bi-sliders',
                                'route' => 'settings.platform',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'key' => 'settings.notification-audiences',
                                'name' => $this->translations('Notification audiences', 'Audiens notifikasi'),
                                'icon' => 'bi bi-bell',
                                'route' => 'notification-audiences.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'key' => 'settings.currencies',
                                'name' => $this->translations('Currencies', 'Mata uang'),
                                'icon' => 'bi bi-currency-exchange',
                                'route' => 'currencies.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'administration.navigation',
                        'name' => $this->translations('Navigation', 'Navigasi'),
                        'icon' => 'bi bi-list-nested',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'key' => 'administration.navigation.menu-list',
                                'name' => $this->translations('Menu list', 'Daftar Menu'),
                                'icon' => 'bi bi-list-ul',
                                'route' => 'menus.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.navigation.trashed',
                                'name' => $this->translations('Trashed menus', 'Menu Terhapus'),
                                'icon' => 'bi bi-trash',
                                'route' => 'menus.trashed',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function navbarTree(): array
    {
        return [
            [
                'name' => $this->translations('Home', 'Beranda'),
                'icon' => 'bi bi-house',
                'route' => 'dashboard',
                'type' => 'p',
            ],
        ];
    }

    /**
     * `en` + `id`, filtered to `config('localization.supported_locales')`.
     *
     * A hardcoded list is how a retired locale keeps reappearing in every seeded
     * node after the locale itself was pruned. Reading the config means the tree
     * only ever writes codes the application still resolves; a locale added later
     * seeds the reference copy until it is curated, never a raw key.
     *
     * @return array<string, string>
     */
    private function translations(string $english, string $indonesian): array
    {
        $values = ['en' => $english, 'id' => $indonesian];

        $out = [];

        foreach ((array) config('localization.supported_locales', ['en']) as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }

            $out[$locale] = $values[$locale] ?? $english;
        }

        return $out !== [] ? $out : ['en' => $english];
    }
}
