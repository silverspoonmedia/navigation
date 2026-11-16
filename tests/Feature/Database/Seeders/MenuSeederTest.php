<?php

namespace App\Domain\Navigation\Tests\Feature\Database\Seeders;

use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Repositories\Eloquent\MenuRepository;
use App\Domain\Navigation\Support\MenuLayout;
use App\Support\Product\Services\ProductActivationService;
use Database\Seeders\ArchivdRoleSeeder;
use App\Domain\Navigation\Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ArchivdRoleSeeder::class);
        $this->seed(MenuSeeder::class);
    }

    public function test_it_seeds_the_exact_grouped_sidebar_tree(): void
    {
        $this->assertSame([
            [
                'name' => 'Workspace',
                'route' => null,
                'type' => 'h',
                'children' => [
                    ['name' => 'Dashboard', 'route' => 'dashboard', 'type' => 'p', 'children' => []],
                    ['name' => 'Notifications', 'route' => 'notifications.index', 'type' => 'p', 'children' => []],
                    ['name' => 'Services', 'route' => 'services.index', 'type' => 'p', 'children' => []],
                ],
            ],
            [
                'name' => 'Products',
                'route' => null,
                'type' => 'h',
                'children' => [
                    [
                        'name' => 'Archivd',
                        'route' => 'archivd.index',
                        'type' => 'p',
                        'children' => [
                            ['name' => 'My Files', 'route' => 'archivd.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Trash', 'route' => 'archivd.trash', 'type' => 'c', 'children' => []],
                            ['name' => 'DMCA Blocklist', 'route' => 'archivd.blocklist.index', 'type' => 'c', 'children' => []],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Administration',
                'route' => null,
                'type' => 'h',
                'children' => [
                    [
                        'name' => 'Access control',
                        'route' => null,
                        'type' => 'p',
                        'children' => [
                            ['name' => 'User management', 'route' => 'users.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Product activations', 'route' => 'product-activations.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Product domain access', 'route' => 'domain-access.index', 'type' => 'c', 'children' => []],
                        ],
                    ],
                    [
                        'name' => 'Payments',
                        'route' => null,
                        'type' => 'p',
                        'children' => [
                            ['name' => 'Payment income', 'route' => 'payments.income.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Invoices', 'route' => 'invoices.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Subscriptions', 'route' => 'subscriptions.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Payment channels', 'route' => 'payment-channels.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Payment gateways', 'route' => 'payment-gateways.index', 'type' => 'c', 'children' => []],
                        ],
                    ],
                    [
                        'name' => 'Inference',
                        'route' => null,
                        'type' => 'p',
                        'children' => [
                            ['name' => 'Model catalog', 'route' => 'inference.model-cards.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Providers', 'route' => 'inference.providers.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Usage events', 'route' => 'inference.usage-events.index', 'type' => 'c', 'children' => []],
                        ],
                    ],
                    [
                        'name' => 'AI connections',
                        'route' => 'ai.connections.index',
                        'type' => 'p',
                        'children' => [],
                    ],
                    [
                        'name' => 'Monitoring',
                        'route' => null,
                        'type' => 'p',
                        'children' => [
                            ['name' => 'Activity log', 'route' => 'activity-logs.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Error log', 'route' => 'error-logs.index', 'type' => 'c', 'children' => []],
                        ],
                    ],
                    [
                        'name' => 'Settings',
                        'route' => null,
                        'type' => 'p',
                        'children' => [
                            ['name' => 'Platform', 'route' => 'settings.platform', 'type' => 'c', 'children' => []],
                            ['name' => 'Archivd', 'route' => 'settings.archivd', 'type' => 'c', 'children' => []],
                            ['name' => 'Microstock', 'route' => 'settings.microstock', 'type' => 'c', 'children' => []],
                            ['name' => 'Notification audiences', 'route' => 'notification-audiences.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Currencies', 'route' => 'currencies.index', 'type' => 'c', 'children' => []],
                        ],
                    ],
                    [
                        'name' => 'Navigation',
                        'route' => null,
                        'type' => 'p',
                        'children' => [
                            ['name' => 'Menu list', 'route' => 'menus.index', 'type' => 'c', 'children' => []],
                            ['name' => 'Trashed menus', 'route' => 'menus.trashed', 'type' => 'c', 'children' => []],
                        ],
                    ],
                ],
            ],
        ], $this->databaseTree(MenuLayout::SIDEBAR));
    }

    public function test_it_seeds_translations_roles_navbar_and_no_debug_surfaces(): void
    {
        $workspace = Menu::query()->where('name->en', 'Workspace')->firstOrFail();
        $this->assertSame([
            'en' => 'Workspace',
            'id' => 'Ruang Kerja',
            'ja' => 'ワークスペース',
            'ko' => '작업 공간',
            'zh_CN' => '工作区',
            'ar' => 'مساحة العمل',
            'ru' => 'Рабочая область',
        ], $workspace->getTranslations('name'));

        $this->assertSame(
            ['Admin', 'Archivd Admin', 'Management', 'Moderator', 'Root'],
            $this->roleNamesForRoute('archivd.blocklist.index'),
        );
        $this->assertSame(
            ['Admin', 'Root'],
            $this->roleNamesForRoute('settings.archivd'),
        );
        $this->assertSame(
            ['Admin', 'Root'],
            $this->roleNamesForRoute('settings.microstock'),
        );

        $this->assertSame([
            ['name' => 'Home', 'route' => 'dashboard', 'type' => 'p', 'children' => []],
        ], $this->databaseTree(MenuLayout::NAVBAR));

        $this->assertFalse(Menu::withTrashed()->where('name->en', 'Debug')->exists());
        $this->assertFalse(Menu::withTrashed()->where('route', 'microstock.index')->exists());
    }

    public function test_it_rebuilds_authoritatively_and_is_logically_idempotent(): void
    {
        $initialTree = [
            'sidebar' => $this->databaseTree(MenuLayout::SIDEBAR),
            'navbar' => $this->databaseTree(MenuLayout::NAVBAR),
        ];

        $custom = Menu::query()->create([
            'layout' => MenuLayout::SIDEBAR,
            'name' => ['en' => 'Custom'],
            'type' => 'h',
            'order' => 4,
            'audience' => MenuAudience::Auth,
        ]);
        $trashed = Menu::query()->create([
            'layout' => MenuLayout::NAVBAR,
            'name' => ['en' => 'Old navbar item'],
            'type' => 'p',
            'order' => 2,
            'audience' => MenuAudience::Auth,
        ]);
        $trashed->forceFill(['order' => null])->save();
        $trashed->delete();

        $this->seed(MenuSeeder::class);

        $this->assertFalse(Menu::withTrashed()->whereKey($custom->id)->exists());
        $this->assertFalse(Menu::withTrashed()->whereKey($trashed->id)->exists());
        $this->assertSame(38, Menu::query()->count());
        $this->assertSame($initialTree, [
            'sidebar' => $this->databaseTree(MenuLayout::SIDEBAR),
            'navbar' => $this->databaseTree(MenuLayout::NAVBAR),
        ]);

        $this->seed(MenuSeeder::class);

        $this->assertSame(38, Menu::query()->count());
        $this->assertSame($initialTree, [
            'sidebar' => $this->databaseTree(MenuLayout::SIDEBAR),
            'navbar' => $this->databaseTree(MenuLayout::NAVBAR),
        ]);
    }

    public function test_role_filtered_tree_exposes_only_relevant_groups(): void
    {
        $user = $this->activatedUser('User', 'user@example.com');
        $userNames = $this->visibleNames($user);

        $this->assertContains('My Files', $userNames);
        $this->assertContains('Trash', $userNames);
        $this->assertNotContains('DMCA Blocklist', $userNames);
        $this->assertNotContains('Registry & pending', $userNames);
        $this->assertNotContains('Administration', $userNames);

        $management = $this->activatedUser('Management', 'management@example.com');
        $managementNames = $this->visibleNames($management);

        $this->assertContains('DMCA Blocklist', $managementNames);
        $this->assertNotContains('Registry & pending', $managementNames);
        $this->assertContains('Access control', $managementNames);
        $this->assertNotContains('Monitoring', $managementNames);

        $moderator = $this->activatedUser('Moderator', 'moderator@example.com');
        $moderatorNames = $this->visibleNames($moderator);

        $this->assertNotContains('Monitoring', $moderatorNames);

        $admin = $this->activatedUser('Admin', 'admin@example.com');
        $adminNames = $this->visibleNames($admin);

        $this->assertNotContains('Registry & pending', $adminNames);
        $this->assertContains('Product domain access', $adminNames);
        $this->assertContains('Monitoring', $adminNames);
        $this->assertContains('Settings', $adminNames);
        $this->assertContains('Platform', $adminNames);
        $this->assertContains('Archivd', $adminNames);
        $this->assertContains('Microstock', $adminNames);
    }

    private function activatedUser(string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role);

        $activations = app(ProductActivationService::class);
        $activations->activateImplicit($user, 'Archivd');

        return $user->fresh();
    }

    /**
     * @return list<string>
     */
    private function visibleNames(User $user): array
    {
        $names = [];
        $walk = function (array $nodes) use (&$walk, &$names): void {
            foreach ($nodes as $node) {
                $names[] = $node['name'];
                $walk($node['children']);
            }
        };
        $walk(app(MenuRepository::class)->navigationTree($user, MenuLayout::SIDEBAR));

        return $names;
    }

    /**
     * @return list<string>
     */
    private function roleNamesForRoute(string $route): array
    {
        return Menu::query()
            ->where('route', $route)
            ->firstOrFail()
            ->roles
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, route: ?string, type: string, children: array}>
     */
    private function databaseTree(string $layout, ?string $parentId = null): array
    {
        return Menu::query()
            ->where('layout', $layout)
            ->where('parent_id', $parentId)
            ->orderBy('order')
            ->get()
            ->map(fn (Menu $menu): array => [
                'name' => $menu->getTranslation('name', 'en'),
                'route' => $menu->route,
                'type' => $menu->type,
                'children' => $this->databaseTree($layout, $menu->id),
            ])
            ->all();
    }
}
