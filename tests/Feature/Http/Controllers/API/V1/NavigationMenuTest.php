<?php

namespace App\Domain\Navigation\Tests\Feature\Http\Controllers\API\V1;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Repositories\Contracts\MenuRepositoryInterface;
use App\Domain\Navigation\Tests\TestCase;
use App\Support\Testing\Concerns\SeedsRolesAndPermissions;
use PHPUnit\Framework\Attributes\DataProvider;

class NavigationMenuTest extends TestCase
{
    use SeedsRolesAndPermissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_guest_navigation_tree_excludes_authenticated_only(): void
    {
        $this->makeMenu(['name' => 'PublicItem', 'type' => 'c', 'audience' => MenuAudience::All]);
        $this->makeMenu(['name' => 'MembersItem', 'type' => 'c', 'audience' => MenuAudience::Auth]);

        $response = $this->getJson('/api/v1/navigation/menus')->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('PublicItem'));
        $this->assertFalse($names->contains('MembersItem'));
    }

    public function test_authenticated_navigation_tree_includes_authenticated_only(): void
    {
        $this->makeMenu(['name' => 'MembersItem', 'type' => 'c', 'audience' => MenuAudience::Auth]);
        $user = $this->userWithRole('User');

        $response = $this->actingAsJwt($user)
            ->getJson('/api/v1/navigation/menus')
            ->assertOk();

        $this->assertTrue(collect($response->json('data'))->pluck('name')->contains('MembersItem'));
    }

    public function test_list_requires_management_role(): void
    {
        $this->getJson('/api/v1/navigation/menus/list')->assertUnauthorized();

        $this->actingAsJwt($this->userWithRole('User'))
            ->getJson('/api/v1/navigation/menus/list')
            ->assertForbidden();

        $this->actingAsJwt($this->userWithRole('Admin'))
            ->getJson('/api/v1/navigation/menus/list')
            ->assertOk();
    }

    public function test_list_query_rows_include_edit_and_delete_actions(): void
    {
        $admin = $this->userWithRole('Admin');
        $menu = $this->makeMenu(['name' => 'QueryActionsMenu', 'type' => 'h']);

        $row = collect(
            $this->actingAsJwt($admin)
                ->getJson('/api/v1/navigation/menus/list')
                ->assertOk()
                ->json('data'),
        )->firstWhere('id', $menu->id);

        $this->assertNotNull($row);
        $this->assertSame(['edit', 'delete'], collect($row['actions'])->pluck('key')->all());
    }

    public function test_admin_can_create_menu_with_role_assignment(): void
    {
        $admin = $this->userWithRole('Admin');

        $response = $this->actingAsJwt($admin)
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Reports',
                'type' => 'h',
                'icon' => 'chart',
                'audience' => MenuAudience::Role->value,
                'roles' => ['Admin'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Reports')
            ->assertJsonPath('data.audience', MenuAudience::Role->value);

        $menuId = $response->json('data.id');
        $adminRoleId = Role::query()->where('name', 'Admin')->where('guard_name', 'api')->value('id');

        $menu = Menu::query()->findOrFail($menuId);
        $this->assertSame(['en' => 'Reports'], $menu->getTranslations('name'));
        $this->assertDatabaseHas('menu_roles', ['menu_id' => $menuId, 'role_id' => $adminRoleId]);
        $this->assertSame([$adminRoleId], $response->json('data.role_ids'));
    }

    public function test_admin_can_create_menu_with_translations_map(): void
    {
        $admin = $this->userWithRole('Admin');

        // A locale map must cover EVERY supported locale (validateTranslatableAllLocales),
        // so build the payload from config rather than hardcoding en/id — this keeps
        // the test correct when locales are added (e.g. ja).
        $locales = config('localization.supported_locales');
        $name = collect($locales)->mapWithKeys(fn (string $l): array => [$l => "Reports {$l}"])->all();
        $description = collect($locales)->mapWithKeys(fn (string $l): array => [$l => "Reports section {$l}"])->all();

        $response = $this->actingAsJwt($admin)
            ->postJson('/api/v1/navigation/menus', [
                'name' => $name,
                'description' => $description,
                'type' => 'h',
                'audience' => MenuAudience::Auth->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Reports en')
            ->assertJsonPath('data.name_translations.en', 'Reports en')
            ->assertJsonPath('data.name_translations.id', 'Reports id')
            ->assertJsonPath('data.description_translations.id', 'Reports section id');

        $menuId = $response->json('data.id');

        $menu = Menu::query()->findOrFail($menuId);
        $this->assertSame($name, $menu->getTranslations('name'));
    }

    public function test_navigation_tree_resolves_name_for_request_locale(): void
    {
        $this->makeMenu([
            'name' => ['en' => 'English label', 'id' => 'Label Indonesia'],
            'type' => 'c',
            'audience' => MenuAudience::All,
        ]);

        app()->setLocale('id');

        $response = $this->getJson('/api/v1/navigation/menus')->assertOk();

        $this->assertTrue(
            collect($response->json('data'))->pluck('name')->contains('Label Indonesia'),
        );
    }

    public function test_navigation_tree_resolves_chinese_translation_for_zh_cn_request(): void
    {
        $this->makeMenu([
            'name' => ['en' => 'English label', 'zh_CN' => '简体中文标签'],
            'type' => 'c',
            'audience' => MenuAudience::All,
        ]);

        app()->setLocale('zh_CN');

        $response = $this->getJson('/api/v1/navigation/menus')->assertOk();

        $this->assertTrue(
            collect($response->json('data'))->pluck('name')->contains('简体中文标签'),
        );
    }

    public function test_spa_locale_header_wins_over_stale_cookie(): void
    {
        $this->makeMenu([
            'name' => ['en' => 'English label', 'id' => 'Label Indonesia', 'ar' => 'تسمية عربية'],
            'type' => 'c',
            'audience' => MenuAudience::All,
        ]);

        // Stale cookie says id, but the SPA's current header says ar. The header
        // must win so a direction-swap reload (which can abort the auth/locale
        // persist that refreshes the cookie) still resolves Arabic.
        $response = $this
            ->withUnencryptedCookie('locale', 'id')
            ->withHeader('X-SPA-Locale', 'ar')
            ->getJson('/api/v1/navigation/menus')
            ->assertOk();

        $this->assertSame('تسمية عربية', $this->findMenuNode($response->json('data'), 'تسمية عربية')['name']);
    }

    public function test_user_db_locale_still_wins_over_header_and_cookie(): void
    {
        $this->makeMenu([
            'name' => ['en' => 'English label', 'id' => 'Label Indonesia', 'ar' => 'تسمية عربية'],
            'type' => 'c',
            'audience' => MenuAudience::All,
        ]);

        $user = $this->userWithRole('User');
        $user->forceFill(['locale' => 'id'])->save();

        // DB preference is authoritative: even with a conflicting header+cookie,
        // the authenticated user's stored locale wins.
        $response = $this->actingAsJwt($user)
            ->withUnencryptedCookie('locale', 'ar')
            ->withHeader('X-SPA-Locale', 'ar')
            ->getJson('/api/v1/navigation/menus')
            ->assertOk();

        $this->assertSame('Label Indonesia', $this->findMenuNode($response->json('data'), 'Label Indonesia')['name']);
    }

    public function test_navigation_tree_cache_isolated_by_locale(): void
    {
        $this->makeMenu([
            'name' => [
                'en' => 'English label',
                'id' => 'Label Indonesia',
                'ja' => '日本語ラベル',
            ],
            'description' => [
                'en' => 'English description',
                'id' => 'Deskripsi Indonesia',
                'ja' => '日本語の説明',
            ],
            'type' => 'c',
            'audience' => MenuAudience::All,
        ]);

        app()->setLocale('en');
        $english = $this->getJson('/api/v1/navigation/menus')->assertOk()->json('data');

        app()->setLocale('id');
        $indonesian = $this->getJson('/api/v1/navigation/menus')->assertOk()->json('data');

        app()->setLocale('ja');
        $japanese = $this->getJson('/api/v1/navigation/menus')->assertOk()->json('data');

        $this->assertSame('English label', $this->findMenuNode($english, 'English label')['name']);
        $this->assertSame('Label Indonesia', $this->findMenuNode($indonesian, 'Label Indonesia')['name']);
        $this->assertSame('Deskripsi Indonesia', $this->findMenuNode($indonesian, 'Label Indonesia')['description']);
        $this->assertSame('日本語ラベル', $this->findMenuNode($japanese, '日本語ラベル')['name']);
        $this->assertSame('日本語の説明', $this->findMenuNode($japanese, '日本語ラベル')['description']);
    }

    public function test_navigation_tree_filters_by_query_and_keeps_ancestors(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->makeMenu(['name' => 'Admin Section', 'type' => 'h']);
        $parent = $this->makeMenu(['name' => 'User Management', 'type' => 'p', 'parent_id' => $header->id]);
        $this->makeMenu(['name' => 'User List', 'type' => 'c', 'parent_id' => $parent->id, 'route' => 'users.index']);
        $this->makeMenu(['name' => 'Menu Manager', 'type' => 'c', 'parent_id' => $parent->id]);

        $response = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/menus?q=user%20list')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Admin Section', $data[0]['name']);
        $this->assertCount(1, $data[0]['children']);
        $this->assertSame('User Management', $data[0]['children'][0]['name']);
        $this->assertCount(1, $data[0]['children'][0]['children']);
        $this->assertSame('User List', $data[0]['children'][0]['children'][0]['name']);
    }

    public function test_navigation_tree_empty_query_returns_full_tree(): void
    {
        $this->makeMenu(['name' => 'Alpha', 'type' => 'c', 'audience' => MenuAudience::All]);
        $this->makeMenu(['name' => 'Beta', 'type' => 'c', 'audience' => MenuAudience::All]);

        $full = $this->getJson('/api/v1/navigation/menus')->assertOk()->json('data');
        $filtered = $this->getJson('/api/v1/navigation/menus?q=')->assertOk()->json('data');

        $this->assertCount(2, $full);
        $this->assertCount(count($full), $filtered);
    }

    public function test_store_rejects_incomplete_translation_map(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => ['en' => 'Only English'],
                'type' => 'c',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name.id']);
    }

    public function test_store_rejects_invalid_type(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', ['name' => 'Bad', 'type' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_store_rejects_unregistered_route_name(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Bad Route',
                'type' => 'h',
                'route' => 'this.route.does.not.exist',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['route']);
    }

    public function test_store_accepts_spa_symbolic_route_name(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Dashboard link',
                'type' => 'h',
                'route' => 'dashboard',
                'position' => '',
                'reference_id' => '',
            ])
            ->assertCreated()
            ->assertJsonPath('data.route', 'dashboard');
    }

    public function test_store_accepts_spa_app_route_not_in_menu_seeder(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Create user',
                'type' => 'h',
                'route' => 'users.create',
                'position' => '',
                'reference_id' => '',
            ])
            ->assertCreated()
            ->assertJsonPath('data.route', 'users.create');
    }

    public function test_store_rejects_denied_spa_auth_route_name(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Login',
                'type' => 'h',
                'route' => 'login',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['route']);
    }

    public function test_update_without_placement_keeps_order(): void
    {
        $admin = $this->userWithRole('Admin');
        $a = $this->makeMenu(['name' => 'A', 'type' => 'p', 'order' => 1]);
        $b = $this->makeMenu(['name' => 'B', 'type' => 'p', 'order' => 2]);

        $this->actingAsJwt($admin)
            ->patchJson('/api/v1/navigation/menus/'.$b->id, [
                'name' => 'B renamed',
                'position' => '',
                'reference_id' => '',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'B renamed')
            ->assertJsonPath('data.order', 2);

        $this->assertSame(1, $a->fresh()->order);
    }

    public function test_store_without_placement_appends_to_group(): void
    {
        $admin = $this->userWithRole('Admin');
        $this->makeMenu(['name' => 'Existing', 'type' => 'h', 'order' => 1]);

        $this->actingAsJwt($admin)
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'New tail',
                'type' => 'h',
                'position' => '',
                'reference_id' => '',
            ])
            ->assertCreated()
            ->assertJsonPath('data.order', 2);
    }

    public function test_admin_can_update_and_reorder_menu(): void
    {
        $admin = $this->userWithRole('Admin');
        $a = $this->makeMenu(['name' => 'A', 'type' => 'p']);
        $b = $this->makeMenu(['name' => 'B', 'type' => 'p']);

        $this->actingAsJwt($admin)
            ->patchJson('/api/v1/navigation/menus/'.$b->id, [
                'name' => 'B renamed',
                'position' => 'before',
                'reference_id' => $a->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'B renamed')
            ->assertJsonPath('data.order', 1);

        $this->assertSame(2, $a->fresh()->order);
    }

    public function test_update_with_cross_group_reference_returns_422(): void
    {
        $admin = $this->userWithRole('Admin');
        $parent = $this->makeMenu(['name' => 'Parent', 'type' => 'p']);
        $child = $this->makeMenu(['name' => 'Child', 'type' => 'c']);

        $this->actingAsJwt($admin)
            ->patchJson('/api/v1/navigation/menus/'.$child->id, [
                'position' => 'before',
                'reference_id' => $parent->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_admin_can_delete_menu(): void
    {
        $admin = $this->userWithRole('Admin');
        $menu = $this->makeMenu(['name' => 'Temp', 'type' => 'c']);

        $this->actingAsJwt($admin)
            ->deleteJson('/api/v1/navigation/menus/'.$menu->id)
            ->assertOk();

        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
    }

    public function test_delete_blocks_non_empty_parent_without_cascade(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->repo()->createMenu(['name' => 'H', 'type' => 'h'], 'append', null);
        $this->repo()->createMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id], 'append', null);

        $this->actingAsJwt($admin)
            ->deleteJson('/api/v1/navigation/menus/'.$header->id)
            ->assertStatus(409)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('menus', ['id' => $header->id, 'deleted_at' => null]);
    }

    public function test_delete_with_cascade_soft_deletes_subtree(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->repo()->createMenu(['name' => 'H', 'type' => 'h'], 'append', null);
        $parent = $this->repo()->createMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id], 'append', null);
        $child = $this->repo()->createMenu(['name' => 'C', 'type' => 'c', 'parent_id' => $parent->id], 'append', null);

        $this->actingAsJwt($admin)
            ->deleteJson('/api/v1/navigation/menus/'.$header->id.'?cascade=1')
            ->assertOk();

        $this->assertSoftDeleted('menus', ['id' => $header->id]);
        $this->assertSoftDeleted('menus', ['id' => $parent->id]);
        $this->assertSoftDeleted('menus', ['id' => $child->id]);
    }

    public function test_admin_can_restore_trashed_subtree(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->repo()->createMenu(['name' => 'H', 'type' => 'h'], 'append', null);
        $parent = $this->repo()->createMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id], 'append', null);
        $child = $this->repo()->createMenu(['name' => 'C', 'type' => 'c', 'parent_id' => $parent->id], 'append', null);
        $this->repo()->deleteMenu($header, true);

        $this->actingAsJwt($admin)
            ->postJson('/api/v1/navigation/menus/'.$header->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.id', $header->id);

        $this->assertDatabaseHas('menus', ['id' => $header->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('menus', ['id' => $parent->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('menus', ['id' => $child->id, 'deleted_at' => null]);
    }

    public function test_restore_appends_menu_to_tail_of_group(): void
    {
        $admin = $this->userWithRole('Admin');
        $a = $this->makeMenu(['name' => 'A', 'type' => 'p']);
        $b = $this->makeMenu(['name' => 'B', 'type' => 'p']);

        $this->repo()->deleteMenu($a);
        $this->assertSame(1, $b->fresh()->order);

        $this->actingAsJwt($admin)
            ->postJson('/api/v1/navigation/menus/'.$a->id.'/restore')
            ->assertOk();

        $this->assertSame(1, $b->fresh()->order);
        $this->assertSame(2, $a->fresh()->order);
    }

    public function test_restore_rejects_when_parent_still_trashed(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->repo()->createMenu(['name' => 'H', 'type' => 'h'], 'append', null);
        $parent = $this->repo()->createMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id], 'append', null);
        $this->repo()->deleteMenu($header, true);

        $this->actingAsJwt($admin)
            ->postJson('/api/v1/navigation/menus/'.$parent->id.'/restore')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSoftDeleted('menus', ['id' => $parent->id]);
    }

    public function test_trashed_list_returns_soft_deleted_menus(): void
    {
        $admin = $this->userWithRole('Admin');
        $menu = $this->makeMenu(['name' => 'Gone', 'type' => 'c']);
        $this->repo()->deleteMenu($menu);

        $response = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/menus/trashed')
            ->assertOk();

        $this->assertTrue(collect($response->json('data'))->pluck('name')->contains('Gone'));
    }

    public function test_trashed_list_query_rows_include_restore_action(): void
    {
        $admin = $this->userWithRole('Admin');
        $menu = $this->makeMenu(['name' => 'Gone', 'type' => 'c']);
        $this->repo()->deleteMenu($menu);

        $row = collect(
            $this->actingAsJwt($admin)
                ->getJson('/api/v1/navigation/menus/trashed')
                ->assertOk()
                ->json('data'),
        )->firstWhere('id', $menu->id);

        $this->assertNotNull($row);
        $this->assertSame(['restore'], collect($row['actions'])->pluck('key')->all());
    }

    public function test_restore_requires_restore_permission(): void
    {
        $menu = $this->makeMenu(['name' => 'Gone', 'type' => 'c']);
        $this->repo()->deleteMenu($menu);

        $this->postJson('/api/v1/navigation/menus/'.$menu->id.'/restore')->assertUnauthorized();

        $this->actingAsJwt($this->userWithRole('User'))
            ->postJson('/api/v1/navigation/menus/'.$menu->id.'/restore')
            ->assertForbidden();
    }

    public function test_store_rejects_parent_that_is_soft_deleted(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->repo()->createMenu(['name' => 'H', 'type' => 'h'], 'append', null);
        $this->repo()->deleteMenu($header);

        $this->actingAsJwt($admin)
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Child of trashed',
                'type' => 'p',
                'parent' => $header->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent']);
    }

    public function test_store_defaults_to_authenticated_audience(): void
    {
        $response = $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'No audience sent',
                'type' => 'h',
            ])
            ->assertCreated()
            ->assertJsonPath('data.audience', MenuAudience::Auth->value);

        $this->assertDatabaseHas('menus', [
            'id' => $response->json('data.id'),
            'audience' => MenuAudience::Auth->value,
        ]);
    }

    public function test_store_rejects_unknown_audience(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Impossible',
                'type' => 'h',
                'audience' => 'nobody',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['audience']);
    }

    public function test_store_rejects_role_audience_without_roles(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Role audience without roles',
                'type' => 'h',
                'audience' => MenuAudience::Role->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonRoleAudiences(): array
    {
        return [
            'all' => [MenuAudience::All->value],
            'auth' => [MenuAudience::Auth->value],
            'guest' => [MenuAudience::Guest->value],
        ];
    }

    #[DataProvider('nonRoleAudiences')]
    public function test_store_rejects_roles_outside_role_audience(string $audience): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Impossible',
                'type' => 'h',
                'audience' => $audience,
                'roles' => ['Admin'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['audience']);
    }

    public function test_store_round_trips_every_audience(): void
    {
        $admin = $this->userWithRole('Admin');

        foreach (MenuAudience::cases() as $audience) {
            $payload = [
                'name' => 'Audience '.$audience->value,
                'type' => 'h',
                'audience' => $audience->value,
            ];

            if ($audience->usesRoles()) {
                $payload['roles'] = ['Admin'];
            }

            $this->actingAsJwt($admin)
                ->postJson('/api/v1/navigation/menus', $payload)
                ->assertCreated()
                ->assertJsonPath('data.audience', $audience->value);
        }
    }

    public function test_update_clears_role_pivots_when_audience_leaves_role(): void
    {
        $admin = $this->userWithRole('Admin');
        $menu = $this->makeMenu(['name' => 'Roled', 'type' => 'h', 'audience' => MenuAudience::Role]);
        $this->repo()->syncRoles($menu, ['Admin']);
        $this->assertDatabaseCount('menu_roles', 1);

        // Moving off the role audience must drop the pivots, otherwise the
        // stored audience would contradict the effective visibility.
        $this->actingAsJwt($admin)
            ->patchJson('/api/v1/navigation/menus/'.$menu->id, [
                'audience' => MenuAudience::Auth->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.audience', MenuAudience::Auth->value);

        $this->assertDatabaseCount('menu_roles', 0);
    }

    public function test_update_rejects_audience_change_that_orphans_roles(): void
    {
        $admin = $this->userWithRole('Admin');
        $menu = $this->makeMenu(['name' => 'Roled', 'type' => 'h', 'audience' => MenuAudience::Role]);
        $this->repo()->syncRoles($menu, ['Admin']);

        // The stored pivots are the effective role set; switching the audience
        // away from `role` without clearing them is rejected.
        $this->actingAsJwt($admin)
            ->patchJson('/api/v1/navigation/menus/'.$menu->id, [
                'audience' => MenuAudience::All->value,
                'roles' => ['Admin'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['audience']);
    }

    public function test_update_without_audience_keeps_existing_value(): void
    {
        $admin = $this->userWithRole('Admin');
        $menu = $this->makeMenu(['name' => 'Guest banner', 'type' => 'h', 'audience' => MenuAudience::Guest]);

        $this->actingAsJwt($admin)
            ->patchJson('/api/v1/navigation/menus/'.$menu->id, ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.audience', MenuAudience::Guest->value);
    }

    public function test_store_rejects_child_without_valid_parent(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', ['name' => 'Orphan', 'type' => 'c'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent']);
    }

    public function test_store_rejects_header_with_parent(): void
    {
        $header = $this->repo()->createMenu(['name' => 'Root H', 'type' => 'h'], 'append', null);

        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Nested Header',
                'type' => 'h',
                'parent' => $header->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent']);
    }

    public function test_update_rejects_reparenting_under_descendant(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->repo()->createMenu(['name' => 'H', 'type' => 'h'], 'append', null);
        $parent = $this->repo()->createMenu(['name' => 'P', 'type' => 'p', 'parent_id' => $header->id], 'append', null);

        // Attempt to make the header a child of its own descendant.
        $this->actingAsJwt($admin)
            ->patchJson('/api/v1/navigation/menus/'.$header->id, ['parent' => $parent->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent']);
    }

    public function test_show_returns_not_found_for_missing_menu(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->getJson('/api/v1/navigation/menus/019e8766-857b-7259-a580-a21e1ddfa27e')
            ->assertNotFound();
    }

    public function test_store_accepts_spa_zh_cn_alias_and_canonicalizes_to_zh_cn_backend(): void
    {
        $admin = $this->userWithRole('Admin');

        // A locale map must cover EVERY supported locale
        // (validateTranslatableAllLocales), so build the payload from config.
        // The `zh-CN` key is the SPA alias of `zh_CN` and must be accepted.
        $name = collect(config('localization.supported_locales'))
            ->mapWithKeys(fn (string $locale): array => [
                $locale === 'zh_CN' ? 'zh-CN' : $locale => $locale === 'zh_CN' ? '中文标签' : "Aliased {$locale}",
            ])
            ->all();

        $response = $this->actingAsJwt($admin)
            ->postJson('/api/v1/navigation/menus', [
                'name' => $name,
                'type' => 'h',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name_translations.zh_CN', '中文标签');

        $menu = Menu::query()->findOrFail($response->json('data.id'));

        $this->assertSame('中文标签', $menu->getTranslation('name', 'zh_CN'));
        $this->assertArrayNotHasKey('zh-CN', $menu->getTranslations('name'));
    }

    public function test_store_merges_mixed_zh_keys_onto_backend_key(): void
    {
        $admin = $this->userWithRole('Admin');

        // A locale map must cover EVERY supported locale
        // (validateTranslatableAllLocales), so build the payload from config.
        // A stale canonical prefill (`zh_CN`) plus an SPA edit (`zh-CN`): the
        // alias is the most recent write and must win the merge.
        $name = collect(config('localization.supported_locales'))
            ->mapWithKeys(fn (string $locale): array => [$locale => "Mixed {$locale}"])
            ->all();

        // Both keys present, alias last: zh_CN stale value + zh-CN edit.
        $name['zh_CN'] = '旧标签';
        $name['zh-CN'] = '新标签';

        $response = $this->actingAsJwt($admin)
            ->postJson('/api/v1/navigation/menus', [
                'name' => $name,
                'type' => 'h',
            ])
            ->assertCreated();

        $menu = Menu::query()->findOrFail($response->json('data.id'));
        $translations = $menu->getTranslations('name');

        $this->assertSame('新标签', $translations['zh_CN']);
        $this->assertArrayNotHasKey('zh-CN', $translations);
    }

    public function test_update_accepts_spa_zh_cn_alias_without_validation_error(): void
    {
        $admin = $this->userWithRole('Admin');
        $menu = $this->makeMenu(['name' => ['en' => 'Original']]);

        // A locale map must cover EVERY supported locale
        // (validateTranslatableAllLocales), so build the payload from config.
        // The `zh-CN` key is the SPA alias of `zh_CN` and must be accepted.
        $name = collect(config('localization.supported_locales'))
            ->mapWithKeys(fn (string $locale): array => [
                $locale === 'zh_CN' ? 'zh-CN' : $locale => $locale === 'zh_CN' ? '中文标签' : "Updated {$locale}",
            ])
            ->all();

        $this->actingAsJwt($admin)
            ->patchJson('/api/v1/navigation/menus/'.$menu->id, [
                'name' => $name,
            ])
            ->assertOk()
            ->assertJsonPath('data.name_translations.zh_CN', '中文标签');

        $fresh = $menu->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('中文标签', $fresh->getTranslation('name', 'zh_CN'));
        $this->assertArrayNotHasKey('zh-CN', $fresh->getTranslations('name'));
    }

    public function test_store_still_rejects_unknown_locale_keys(): void
    {
        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => [
                    'en' => 'Bad locale',
                    'fr' => 'Clé inconnue',
                ],
                'type' => 'h',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Create a menu through the repository so `order` is assigned the same way
     * the application assigns it (contiguous within the sibling group).
     *
     * @param  array<string, mixed>  $attributes
     */
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function findMenuNode(array $nodes, string $name): array
    {
        foreach ($nodes as $node) {
            if (($node['name'] ?? null) === $name) {
                return $node;
            }

            $found = $this->findMenuNode($node['children'] ?? [], $name);
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    private function makeMenu(array $attributes): Menu
    {
        return $this->repo()->createMenu(
            array_merge(['name' => 'Menu', 'type' => 'c'], $attributes),
            'append',
            null,
        );
    }

    private function repo(): MenuRepositoryInterface
    {
        return app(MenuRepositoryInterface::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function actingAsJwt(User $user): static
    {
        $token = auth('api')->login($user);

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
