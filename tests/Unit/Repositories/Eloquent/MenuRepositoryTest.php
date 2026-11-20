<?php

namespace App\Domain\Navigation\Tests\Unit\Repositories\Eloquent;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Exceptions\InvalidMenuParentException;
use App\Domain\Navigation\Exceptions\InvalidMenuPositionException;
use App\Domain\Navigation\Exceptions\MenuHasChildrenException;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Repositories\Eloquent\MenuRepository;
use App\Domain\Navigation\Tests\TestCase;
use App\Support\Testing\Concerns\SeedsRolesAndPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MenuRepositoryTest extends TestCase
{
    use SeedsRolesAndPermissions;

    private MenuRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->repository = app(MenuRepository::class);
    }

    public function test_create_appends_with_contiguous_order(): void
    {
        $a = $this->makeMenu(['name' => 'A', 'type' => 'p']);
        $b = $this->makeMenu(['name' => 'B', 'type' => 'p']);
        $c = $this->makeMenu(['name' => 'C', 'type' => 'p']);

        $this->assertSame([1, 2, 3], [$a->fresh()->order, $b->fresh()->order, $c->fresh()->order]);
    }

    public function test_create_with_before_and_after_positions(): void
    {
        $first = $this->makeMenu(['name' => 'First', 'type' => 'p']);
        $last = $this->makeMenu(['name' => 'Last', 'type' => 'p']);

        $middle = $this->repository->createMenu(
            ['name' => 'Middle', 'type' => 'p'],
            'after',
            $first->id,
        );

        $this->assertSame(['First', 'Middle', 'Last'], $this->orderedNames(null, 'p'));

        $this->repository->createMenu(['name' => 'Zero', 'type' => 'p'], 'before', $first->id);

        $this->assertSame(['Zero', 'First', 'Middle', 'Last'], $this->orderedNames(null, 'p'));
        $this->assertSame([1, 2, 3, 4], $this->orderedValues(null, 'p'));
        $this->assertNotNull($middle);
        $this->assertNotNull($last);
    }

    public function test_update_position_reorders_within_group(): void
    {
        $a = $this->makeMenu(['name' => 'A', 'type' => 'p']);
        $b = $this->makeMenu(['name' => 'B', 'type' => 'p']);
        $c = $this->makeMenu(['name' => 'C', 'type' => 'p']);

        $this->repository->updateMenuPosition($c, 'before', $a->id);

        $this->assertSame(['C', 'A', 'B'], $this->orderedNames(null, 'p'));
        $this->assertSame([1, 2, 3], $this->orderedValues(null, 'p'));
        $this->assertNotNull($b);
    }

    public function test_delete_compacts_remaining_order(): void
    {
        $a = $this->makeMenu(['name' => 'A', 'type' => 'p']);
        $b = $this->makeMenu(['name' => 'B', 'type' => 'p']);
        $c = $this->makeMenu(['name' => 'C', 'type' => 'p']);

        $this->repository->deleteMenu($b);

        $this->assertSame(['A', 'C'], $this->orderedNames(null, 'p'));
        $this->assertSame([1, 2], $this->orderedValues(null, 'p'));
        $this->assertNotNull($a);
        $this->assertNotNull($c);
    }

    public function test_position_against_other_group_throws(): void
    {
        $parentType = $this->makeMenu(['name' => 'Parent', 'type' => 'p']);
        $childType = $this->makeMenu(['name' => 'Child', 'type' => 'c']);

        $this->expectException(InvalidMenuPositionException::class);

        $this->repository->updateMenuPosition($childType, 'before', $parentType->id);
    }

    public function test_sync_roles_resolves_by_name_and_id(): void
    {
        $menu = $this->makeMenu(['name' => 'Roled', 'type' => 'c']);
        $adminId = Role::query()->where('name', 'Admin')->where('guard_name', 'api')->value('id');

        $this->repository->syncRoles($menu, ['Moderator', $adminId]);

        $names = $menu->fresh()->roles->pluck('name')->sort()->values()->all();
        $this->assertSame(['Admin', 'Moderator'], $names);

        $this->repository->syncRoles($menu, []);
        $this->assertCount(0, $menu->fresh()->roles);
    }

    public function test_navigation_tree_hides_authenticated_only_from_guest(): void
    {
        $this->makeMenu(['name' => 'Members', 'type' => 'c', 'audience' => MenuAudience::Auth]);

        $this->assertNotContains('Members', $this->treeNames(null));
        $this->assertContains('Members', $this->treeNames($this->userWithRole('User')));
    }

    public function test_navigation_tree_hides_guest_only_from_authenticated(): void
    {
        $this->makeMenu(['name' => 'GuestBanner', 'type' => 'c', 'audience' => MenuAudience::Guest]);

        $this->assertContains('GuestBanner', $this->treeNames(null));
        $this->assertNotContains('GuestBanner', $this->treeNames($this->userWithRole('User')));
    }

    public function test_navigation_tree_shows_all_audience_to_everyone(): void
    {
        $this->makeMenu(['name' => 'OpenItem', 'type' => 'c', 'audience' => MenuAudience::All]);

        $this->assertContains('OpenItem', $this->treeNames(null));
        $this->assertContains('OpenItem', $this->treeNames($this->userWithRole('User')));
    }

    public function test_navigation_tree_enforces_role_restriction(): void
    {
        $menu = $this->makeMenu(['name' => 'AdminArea', 'type' => 'c', 'audience' => MenuAudience::Role]);
        $this->repository->syncRoles($menu, ['Admin']);

        $this->assertNotContains('AdminArea', $this->treeNames(null));
        $this->assertNotContains('AdminArea', $this->treeNames($this->userWithRole('User')));
        $this->assertContains('AdminArea', $this->treeNames($this->userWithRole('Admin')));
    }

    public function test_navigation_tree_respects_include_and_exclude(): void
    {
        $included = $this->userWithRole('User');
        $excluded = $this->userWithRole('User');

        $includeMenu = $this->makeMenu(['name' => 'IncludeOnly', 'type' => 'c']);
        $this->repository->syncUserIncludes($includeMenu, [$included->id]);

        $excludeMenu = $this->makeMenu(['name' => 'ExcludeOne', 'type' => 'c']);
        $this->repository->syncUserExcludes($excludeMenu, [$excluded->id]);

        $this->assertContains('IncludeOnly', $this->treeNames($included));
        $this->assertNotContains('IncludeOnly', $this->treeNames($excluded));

        $this->assertContains('ExcludeOne', $this->treeNames($included));
        $this->assertNotContains('ExcludeOne', $this->treeNames($excluded));
    }

    public function test_navigation_tree_prunes_empty_parents(): void
    {
        $header = $this->makeMenu(['name' => 'Empty Header', 'type' => 'h', 'audience' => MenuAudience::All]);
        $this->makeMenu(['name' => 'Empty Parent', 'type' => 'p', 'parent_id' => $header->id, 'audience' => MenuAudience::All]);

        // Header/parent with no visible leaf must not appear.
        $this->assertNotContains('Empty Header', $this->treeNames(null));
    }

    public function test_navigation_tree_keeps_leaf_parent_with_route(): void
    {
        $header = $this->makeMenu(['name' => 'Main', 'type' => 'h']);
        $this->makeMenu([
            'name' => 'Dashboard',
            'type' => 'p',
            'parent_id' => $header->id,
            'route' => 'dashboard',
        ]);

        $names = $this->treeNames($this->userWithRole('Admin'));

        $this->assertContains('Main', $names);
        $this->assertContains('Dashboard', $names);
    }

    public function test_navigation_tree_survives_a_serializing_cache_store(): void
    {
        // The default test store is `array` (no serialization). Force a store
        // that round-trips through serialize()/unserialize() like the real
        // `database` driver, so a cached value that cannot be deserialized
        // (e.g. hydrated Eloquent models) is caught here.
        config(['cache.default' => 'database']);
        Cache::clear();

        $this->makeMenu(['name' => 'Cached', 'type' => 'c']);

        // First read populates the cache (returns the freshly built value).
        $this->assertContains('Cached', $this->treeNames($this->userWithRole('Admin')));

        // Second read must deserialize the cached value from the store.
        $this->assertContains('Cached', $this->treeNames($this->userWithRole('Admin')));
    }

    public function test_soft_deleted_menu_is_excluded_from_tree_and_list(): void
    {
        $menu = $this->makeMenu(['name' => 'Gone', 'type' => 'c', 'audience' => MenuAudience::All]);

        $this->repository->deleteMenu($menu);

        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
        $this->assertNotContains('Gone', $this->treeNames(null));
        $this->assertCount(0, $this->repository->adminFlatList(null));
    }

    public function test_delete_blocks_non_empty_parent_without_cascade(): void
    {
        $header = $this->makeMenu(['name' => 'H', 'type' => 'h']);
        $this->makeMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id]);

        $this->expectException(MenuHasChildrenException::class);

        $this->repository->deleteMenu($header);
    }

    public function test_delete_with_cascade_soft_deletes_whole_subtree(): void
    {
        $header = $this->makeMenu(['name' => 'H', 'type' => 'h', 'audience' => MenuAudience::All]);
        $parent = $this->makeMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id, 'audience' => MenuAudience::All]);
        $child = $this->makeMenu(['name' => 'C', 'type' => 'c', 'parent_id' => $parent->id, 'audience' => MenuAudience::All]);

        $this->repository->deleteMenu($header, true);

        $this->assertSoftDeleted('menus', ['id' => $header->id]);
        $this->assertSoftDeleted('menus', ['id' => $parent->id]);
        $this->assertSoftDeleted('menus', ['id' => $child->id]);
        $this->assertNotContains('H', $this->treeNames(null));
    }

    public function test_restore_brings_back_subtree_and_appends_order(): void
    {
        $header = $this->makeMenu(['name' => 'H', 'type' => 'h', 'audience' => MenuAudience::All]);
        $parent = $this->makeMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id, 'audience' => MenuAudience::All]);
        $child = $this->makeMenu(['name' => 'C', 'type' => 'c', 'parent_id' => $parent->id, 'audience' => MenuAudience::All]);
        $sibling = $this->makeMenu(['name' => 'H2', 'type' => 'h', 'audience' => MenuAudience::All]);

        $this->repository->deleteMenu($header, true);
        $this->assertSame(1, $sibling->fresh()->order);

        $this->repository->restoreMenu($header);

        $this->assertNull($header->fresh()->deleted_at);
        $this->assertNull($parent->fresh()->deleted_at);
        $this->assertNull($child->fresh()->deleted_at);

        // Restored header re-appended to the tail of the root group, behind H2.
        $this->assertSame(1, $sibling->fresh()->order);
        $this->assertSame(2, $header->fresh()->order);
        $this->assertContains('H', $this->treeNames(null));
    }

    public function test_restore_rejects_when_parent_is_still_trashed(): void
    {
        $header = $this->makeMenu(['name' => 'H', 'type' => 'h']);
        $parent = $this->makeMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id]);
        $this->repository->deleteMenu($header, true);

        $this->expectException(InvalidMenuParentException::class);

        $this->repository->restoreMenu($parent);
    }

    public function test_trashed_list_returns_only_soft_deleted(): void
    {
        $kept = $this->makeMenu(['name' => 'Kept', 'type' => 'c']);
        $gone = $this->makeMenu(['name' => 'Gone', 'type' => 'c']);
        $this->repository->deleteMenu($gone);

        $names = array_map(fn (Menu $m) => $m->name, $this->repository->trashedList(null));

        $this->assertSame(['Gone'], $names);
        $this->assertNotNull($kept->fresh());
    }

    public function test_navigation_cache_is_busted_when_role_is_renamed(): void
    {
        $user = $this->userWithRole('Moderator');
        $menu = $this->makeMenu(['name' => 'ModOnly', 'type' => 'c', 'audience' => MenuAudience::Role]);
        $this->repository->syncRoles($menu, ['Moderator']);

        // Populate the cached snapshot with the current role name.
        $this->assertContains('ModOnly', $this->treeNames($user));

        // Renaming the role must flush the snapshot via the model event, so the
        // rebuilt gate uses the new name instead of the stale cached one.
        Role::query()->where('name', 'Moderator')->where('guard_name', 'api')
            ->first()->update(['name' => 'Mod2']);

        $this->assertContains('ModOnly', $this->treeNames($user->fresh()));
    }

    public function test_factory_states_build_hierarchy_without_order_collision(): void
    {
        $header = Menu::factory()->header()->create();
        $parent = Menu::factory()->parentItem($header)->create();
        Menu::factory()->count(3)->child($parent)->create();

        // Three NULL-order children in the same group coexist under the unique index.
        $this->assertSame('h', $header->type);
        $this->assertSame($header->id, $parent->parent_id);
        $this->assertDatabaseCount('menus', 5);
    }

    public function test_admin_flat_list_is_depth_first(): void
    {
        $header = $this->makeMenu(['name' => 'H', 'type' => 'h']);
        $parent = $this->makeMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id]);
        $this->makeMenu(['name' => 'C1', 'type' => 'c', 'parent_id' => $parent->id]);
        $this->makeMenu(['name' => 'C2', 'type' => 'c', 'parent_id' => $parent->id]);

        $names = array_map(fn (Menu $m) => $m->name, $this->repository->adminFlatList(null));

        $this->assertSame(['H', 'P', 'C1', 'C2'], $names);
    }

    public function test_navigation_tree_masks_parent_route_when_children_visible(): void
    {
        $header = $this->makeMenu(['name' => 'Products', 'type' => 'h']);
        $parent = $this->makeMenu([
            'name' => 'Expand Parent',
            'type' => 'p',
            'parent_id' => $header->id,
            'route' => 'dashboard',
        ]);
        $this->makeMenu([
            'name' => 'Leaf',
            'type' => 'c',
            'parent_id' => $parent->id,
            'route' => 'dashboard',
        ]);

        $node = $this->treeNodeByName($this->userWithRole('Admin'), 'Expand Parent');

        $this->assertNotNull($node);
        $this->assertSame('#', $node['route']);
        $this->assertNotSame([], $node['children']);
    }

    public function test_navigation_tree_restores_parent_route_when_children_pruned(): void
    {
        $header = $this->makeMenu(['name' => 'Products', 'type' => 'h']);
        $parent = $this->makeMenu([
            'name' => 'Leaf Parent',
            'type' => 'p',
            'parent_id' => $header->id,
            'route' => 'dashboard',
        ]);
        $child = $this->makeMenu([
            'name' => 'Admin Only Child',
            'type' => 'c',
            'parent_id' => $parent->id,
            'route' => 'dashboard',
        ]);
        $this->repository->syncRoles($child, ['Admin']);

        $userNode = $this->treeNodeByName($this->userWithRole('User'), 'Leaf Parent');

        $this->assertNotNull($userNode);
        $this->assertSame('dashboard', $userNode['route']);
        $this->assertSame([], $userNode['children']);
    }

    public function test_navigation_tree_second_call_does_not_query_menus_table(): void
    {
        $this->makeMenu(['name' => 'Cached Leaf', 'type' => 'c', 'route' => 'dashboard']);
        $user = $this->userWithRole('Admin');

        // Warm snapshot + presented tree.
        $this->repository->navigationTree($user, 'sidebar');

        $menuQueries = 0;
        DB::listen(function ($query) use (&$menuQueries): void {
            if (str_contains($query->sql, ' from `menus`') || str_contains($query->sql, ' from "menus"')) {
                $menuQueries++;
            }
        });

        $this->assertContains('Cached Leaf', $this->treeNames($user));
        $this->assertSame(0, $menuQueries);
    }

    public function test_presented_tree_refreshes_after_menu_mutation(): void
    {
        $user = $this->userWithRole('Admin');
        $this->makeMenu(['name' => 'Before', 'type' => 'c', 'route' => 'dashboard']);
        $this->assertContains('Before', $this->treeNames($user));

        $this->makeMenu(['name' => 'After', 'type' => 'c', 'route' => 'dashboard']);
        $this->assertContains('After', $this->treeNames($user));
    }

    public function test_forget_presented_tree_for_user_forces_rebuild(): void
    {
        $header = $this->makeMenu(['name' => 'H', 'type' => 'h']);
        $parent = $this->makeMenu([
            'name' => 'P',
            'type' => 'p',
            'parent_id' => $header->id,
            'route' => 'dashboard',
        ]);
        $child = $this->makeMenu([
            'name' => 'Secret',
            'type' => 'c',
            'parent_id' => $parent->id,
            'route' => 'dashboard',
        ]);
        $this->repository->syncRoles($child, ['Admin']);

        $admin = $this->userWithRole('Admin');
        $this->assertContains('Secret', $this->treeNames($admin));

        // Simulate role loss without going through UserManagementService.
        $admin->syncRoles(['User']);
        $this->repository->forgetPresentedTreeFor($admin);

        $this->assertNotContains('Secret', $this->treeNames($admin));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeMenu(array $attributes): Menu
    {
        return $this->repository->createMenu(
            array_merge(['name' => 'Menu', 'type' => 'c'], $attributes),
            'append',
            null,
        );
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function treeNodeByName(?User $user, string $name): ?array
    {
        $found = null;
        $walk = function (array $nodes) use (&$walk, &$found, $name): void {
            foreach ($nodes as $node) {
                if ($node['name'] === $name) {
                    $found = $node;

                    return;
                }
                $walk($node['children'] ?? []);
            }
        };
        $walk($this->repository->navigationTree($user, 'sidebar'));

        return $found;
    }

    /**
     * @return list<string>
     */
    private function orderedNames(?string $parentId, string $type): array
    {
        return Menu::query()->byParent($parentId)->where('type', $type)
            ->orderBy('order')->pluck('name')->all();
    }

    /**
     * @return list<int>
     */
    private function orderedValues(?string $parentId, string $type): array
    {
        return Menu::query()->byParent($parentId)->where('type', $type)
            ->orderBy('order')->pluck('order')->map(fn ($o) => (int) $o)->all();
    }

    /**
     * @return list<string>
     */
    private function treeNames(?User $user): array
    {
        $names = [];
        $walk = function (array $nodes) use (&$walk, &$names): void {
            foreach ($nodes as $node) {
                $names[] = $node['name'];
                $walk($node['children']);
            }
        };
        $walk($this->repository->navigationTree($user, 'sidebar'));

        return $names;
    }
}
