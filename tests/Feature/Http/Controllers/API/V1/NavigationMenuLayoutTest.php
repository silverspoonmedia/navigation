<?php

namespace App\Domain\Navigation\Tests\Feature\Http\Controllers\API\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Repositories\Contracts\MenuRepositoryInterface;
use App\Domain\Navigation\Support\MenuLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRolesAndPermissions;
use Tests\TestCase;

class NavigationMenuLayoutTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRolesAndPermissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_navigation_tree_is_scoped_by_layout(): void
    {
        $this->repo()->createMenu([
            'name' => 'SidebarOnly',
            'type' => 'p',
            'layout' => MenuLayout::SIDEBAR,
            'route' => 'dashboard',
        ], 'append', null);

        $this->repo()->createMenu([
            'name' => 'NavbarOnly',
            'type' => 'p',
            'layout' => MenuLayout::NAVBAR,
            'route' => 'users.index',
        ], 'append', null);

        $user = $this->userWithRole('Admin');

        $sidebar = $this->actingAsJwt($user)
            ->getJson('/api/v1/navigation/menus?layout=sidebar')
            ->assertOk()
            ->json('data');

        $navbar = $this->actingAsJwt($user)
            ->getJson('/api/v1/navigation/menus?layout=navbar')
            ->assertOk()
            ->json('data');

        $this->assertSame(['SidebarOnly'], collect($sidebar)->pluck('name')->all());
        $this->assertSame(['NavbarOnly'], collect($navbar)->pluck('name')->all());
    }

    public function test_layouts_endpoint_exposes_configured_layouts(): void
    {
        $layouts = $this->actingAsJwt($this->userWithRole('Admin'))
            ->getJson('/api/v1/navigation/menus/layouts')
            ->assertOk()
            ->json('data.layouts');

        $this->assertContains('sidebar', $layouts);
        $this->assertContains('navbar', $layouts);
    }

    public function test_store_rejects_parent_from_different_layout(): void
    {
        $sidebarHeader = $this->repo()->createMenu([
            'name' => 'Sidebar Header',
            'type' => 'h',
            'layout' => MenuLayout::SIDEBAR,
        ], 'append', null);

        $this->actingAsJwt($this->userWithRole('Admin'))
            ->postJson('/api/v1/navigation/menus', [
                'name' => 'Wrong Branch',
                'type' => 'p',
                'layout' => MenuLayout::NAVBAR,
                'parent' => $sidebarHeader->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent']);
    }

    public function test_admin_list_is_scoped_by_layout(): void
    {
        $this->repo()->createMenu(['name' => 'Sidebar Item', 'type' => 'p', 'layout' => MenuLayout::SIDEBAR, 'route' => 'dashboard'], 'append', null);
        $this->repo()->createMenu(['name' => 'Navbar Item', 'type' => 'p', 'layout' => MenuLayout::NAVBAR, 'route' => 'users.index'], 'append', null);

        $sidebar = $this->actingAsJwt($this->userWithRole('Admin'))
            ->getJson('/api/v1/navigation/menus/list?layout=sidebar')
            ->assertOk()
            ->json('data');

        $navbar = $this->actingAsJwt($this->userWithRole('Admin'))
            ->getJson('/api/v1/navigation/menus/list?layout=navbar')
            ->assertOk()
            ->json('data');

        $this->assertSame(['Sidebar Item'], collect($sidebar)->pluck('name')->all());
        $this->assertSame(['Navbar Item'], collect($navbar)->pluck('name')->all());
    }

    public function test_trashed_list_is_scoped_by_layout_and_includes_ancestor_chain(): void
    {
        $repo = $this->repo();

        $sidebarHeader = $repo->createMenu(['name' => 'Sidebar Section', 'type' => 'h', 'layout' => MenuLayout::SIDEBAR], 'append', null);
        $navbarHeader = $repo->createMenu(['name' => 'Navbar Section', 'type' => 'h', 'layout' => MenuLayout::NAVBAR], 'append', null);

        $sidebarParent = $repo->createMenu([
            'name' => 'Sidebar Parent',
            'type' => 'p',
            'layout' => MenuLayout::SIDEBAR,
            'parent_id' => $sidebarHeader->id,
        ], 'append', null);

        $navbarParent = $repo->createMenu([
            'name' => 'Navbar Parent',
            'type' => 'p',
            'layout' => MenuLayout::NAVBAR,
            'parent_id' => $navbarHeader->id,
        ], 'append', null);

        $sidebarChild = $repo->createMenu([
            'name' => 'Duplicate name',
            'type' => 'c',
            'layout' => MenuLayout::SIDEBAR,
            'parent_id' => $sidebarParent->id,
        ], 'append', null);

        $navbarChild = $repo->createMenu([
            'name' => 'Duplicate name',
            'type' => 'c',
            'layout' => MenuLayout::NAVBAR,
            'parent_id' => $navbarParent->id,
        ], 'append', null);

        $repo->deleteMenu($sidebarChild);
        $repo->deleteMenu($navbarChild);

        $sidebarRows = $this->actingAsJwt($this->userWithRole('Admin'))
            ->getJson('/api/v1/navigation/menus/trashed?layout=sidebar')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $sidebarRows);
        $this->assertSame('Duplicate name', $sidebarRows[0]['name']);
        $this->assertSame(
            ['Sidebar Section', 'Sidebar Parent'],
            array_column($sidebarRows[0]['ancestor_chain'], 'name'),
        );
        $this->assertSame('Sidebar Section › Sidebar Parent', $sidebarRows[0]['ancestor_path']);

        $navbarRows = $this->actingAsJwt($this->userWithRole('Admin'))
            ->getJson('/api/v1/navigation/menus/trashed?layout=navbar')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $navbarRows);
        $this->assertSame(
            ['Navbar Section', 'Navbar Parent'],
            array_column($navbarRows[0]['ancestor_chain'], 'name'),
        );
    }

    public function test_sibling_order_is_independent_per_layout(): void
    {
        $this->repo()->createMenu(['name' => 'Sidebar A', 'type' => 'p', 'layout' => MenuLayout::SIDEBAR, 'route' => 'dashboard', 'audience' => MenuAudience::All], 'append', null);
        $this->repo()->createMenu(['name' => 'Navbar A', 'type' => 'p', 'layout' => MenuLayout::NAVBAR, 'route' => 'users.index', 'audience' => MenuAudience::All], 'append', null);
        $this->repo()->createMenu(['name' => 'Sidebar B', 'type' => 'p', 'layout' => MenuLayout::SIDEBAR, 'route' => 'notifications.index', 'audience' => MenuAudience::All], 'append', null);
        $this->repo()->createMenu(['name' => 'Navbar B', 'type' => 'p', 'layout' => MenuLayout::NAVBAR, 'route' => 'services.index', 'audience' => MenuAudience::All], 'append', null);

        $sidebarNames = collect($this->repo()->navigationTree(null, MenuLayout::SIDEBAR))->pluck('name')->all();
        $navbarNames = collect($this->repo()->navigationTree(null, MenuLayout::NAVBAR))->pluck('name')->all();

        $this->assertSame(['Sidebar A', 'Sidebar B'], $sidebarNames);
        $this->assertSame(['Navbar A', 'Navbar B'], $navbarNames);
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
