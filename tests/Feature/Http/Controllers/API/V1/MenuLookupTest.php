<?php

namespace App\Domain\Navigation\Tests\Feature\Http\Controllers\API\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SeedsRolesAndPermissions;
use Tests\TestCase;

/**
 * Select2 menu lookup for the Dynamic Menu CRUD (`/api/v1/navigation/lookups/menus`).
 *
 * Moved here with the endpoint: it reads `Menu`, which this package owns, so it
 * left Identity's lookups when the kernel became `silverspoonmedia/platform`.
 */
class MenuLookupTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRolesAndPermissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_menu_lookup_requires_view_menu_permission(): void
    {
        $this->actingAsJwt($this->userWithRole('User'))
            ->getJson('/api/v1/navigation/lookups/menus')
            ->assertForbidden();

        $this->actingAsJwt($this->userWithRole('Admin'))
            ->getJson('/api/v1/navigation/lookups/menus')
            ->assertOk();
    }

    public function test_menu_lookup_searches_and_excludes_current_menu(): void
    {
        $admin = $this->userWithRole('Admin');
        $current = $this->makeMenu(['name' => ['en' => 'Current Menu'], 'type' => 'c']);
        $sibling = $this->makeMenu(['name' => ['en' => 'Sibling Menu'], 'type' => 'c']);
        $otherLayout = $this->makeMenu(['name' => ['en' => 'Other Layout'], 'type' => 'c', 'layout' => 'navbar']);

        $response = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/lookups/menus?'.http_build_query([
                'layout' => 'sidebar',
                'exclude' => $current->id,
                'q' => 'Sibling',
            ]))
            ->assertOk();

        $ids = collect($response->json('data.results'))->pluck('id');
        $this->assertTrue($ids->contains($sibling->id));
        $this->assertFalse($ids->contains($current->id));
        $this->assertFalse($ids->contains($otherLayout->id));
    }

    public function test_menu_lookup_returns_nested_tree_per_root_group(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->makeMenu(['name' => ['en' => 'Header Menu'], 'type' => 'h']);
        $child = $this->makeMenu(['name' => ['en' => 'Child Menu'], 'type' => 'c', 'parent_id' => $header->id]);
        $grandchild = $this->makeMenu(['name' => ['en' => 'Grandchild Menu'], 'type' => 'c', 'parent_id' => $child->id]);

        $response = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/lookups/menus?'.http_build_query(['layout' => 'sidebar']))
            ->assertOk();

        $results = collect($response->json('data.results'));

        // One nested node per top-level root; the header carries its subtree.
        $headerNode = $results->firstWhere('id', $header->id);
        $this->assertNotNull($headerNode);
        $this->assertSame('Header Menu · h', $headerNode['text']);
        $this->assertSame(0, $headerNode['level']);
        $this->assertCount(1, $headerNode['children']);

        $childNode = $headerNode['children'][0];
        $this->assertSame($child->id, $childNode['id']);
        $this->assertSame('Child Menu · c', $childNode['text']);
        $this->assertSame(1, $childNode['level']);
        $this->assertCount(1, $childNode['children']);

        $grandchildNode = $childNode['children'][0];
        $this->assertSame($grandchild->id, $grandchildNode['id']);
        $this->assertSame(2, $grandchildNode['level']);
        $this->assertSame([], $grandchildNode['children']);
    }

    public function test_menu_lookup_paginates_root_groups(): void
    {
        $admin = $this->userWithRole('Admin');
        $headerA = $this->makeMenu(['name' => ['en' => 'Header A'], 'type' => 'h', 'order' => 1]);
        $headerB = $this->makeMenu(['name' => ['en' => 'Header B'], 'type' => 'h', 'order' => 2]);
        $this->makeMenu(['name' => ['en' => 'Header C'], 'type' => 'h', 'order' => 3]);

        $pageOne = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/lookups/menus?'.http_build_query(['layout' => 'sidebar', 'per_page' => 2]))
            ->assertOk()
            ->assertJsonPath('data.pagination.more', true);

        $pageOneIds = collect($pageOne->json('data.results'))->pluck('id');
        $this->assertSame([$headerA->id, $headerB->id], $pageOneIds->all());

        $pageTwo = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/lookups/menus?'.http_build_query(['layout' => 'sidebar', 'per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->assertJsonPath('data.pagination.more', false);

        $this->assertCount(1, $pageTwo->json('data.results'));
    }

    public function test_menu_lookup_search_keeps_matching_branches_with_ancestors(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->makeMenu(['name' => ['en' => 'Workspace'], 'type' => 'h']);
        $child = $this->makeMenu(['name' => ['en' => 'Payments'], 'type' => 'c', 'parent_id' => $header->id]);
        $other = $this->makeMenu(['name' => ['en' => 'Unrelated'], 'type' => 'h']);

        $response = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/lookups/menus?'.http_build_query(['layout' => 'sidebar', 'q' => 'Payments']))
            ->assertOk();

        $results = collect($response->json('data.results'));
        $this->assertCount(1, $results);

        $branch = $results->first();
        $this->assertSame($header->id, $branch['id']);
        $this->assertCount(1, $branch['children']);
        $this->assertSame($child->id, $branch['children'][0]['id']);
        $this->assertTrue(collect($results->pluck('id'))->doesntContain($other->id));
    }

    public function test_menu_lookup_exclude_only_drops_the_root_itself(): void
    {
        $admin = $this->userWithRole('Admin');
        $headerA = $this->makeMenu(['name' => ['en' => 'Header A'], 'type' => 'h']);
        $childA = $this->makeMenu(['name' => ['en' => 'A1'], 'type' => 'c', 'parent_id' => $headerA->id]);
        $headerB = $this->makeMenu(['name' => ['en' => 'Header B'], 'type' => 'h']);

        $response = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/lookups/menus?'.http_build_query(['layout' => 'sidebar', 'exclude' => $headerA->id]))
            ->assertOk();

        $ids = collect($response->json('data.results'))->pluck('id');
        $this->assertFalse($ids->contains($headerA->id));
        // Descendants of the excluded root are unreachable, so they vanish too.
        $this->assertFalse($ids->contains($childA->id));
        $this->assertTrue($ids->contains($headerB->id));
    }

    public function test_menu_lookup_hydrates_ids_with_grouping_metadata(): void
    {
        $admin = $this->userWithRole('Admin');
        $header = $this->makeMenu(['name' => ['en' => 'Hydrate Header'], 'type' => 'h']);
        $child = $this->makeMenu(['name' => ['en' => 'Hydrate Child'], 'type' => 'c', 'parent_id' => $header->id]);

        $response = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/lookups/menus?'.http_build_query([
                'layout' => 'sidebar',
                'ids' => [$child->id],
            ]))
            ->assertOk();

        $result = $response->json('data.results.0');
        $this->assertSame($child->id, $result['id']);
        $this->assertSame('Hydrate Header · h', $result['group']);
        $this->assertSame(1, $result['level']);
        $this->assertSame('Hydrate Header · h', $result['parent_text']);
    }

    public function test_menu_lookup_orders_root_groups_in_tree_order(): void
    {
        $admin = $this->userWithRole('Admin');
        // Roots sorted by (type rank, order): h A (order 1), h B (order 2).
        // Siblings within each subtree sorted the same way regardless of the
        // order they were created in.
        $headerA = $this->makeMenu(['name' => ['en' => 'Header A'], 'type' => 'h', 'order' => 1]);
        $headerB = $this->makeMenu(['name' => ['en' => 'Header B'], 'type' => 'h', 'order' => 2]);
        $this->makeMenu(['name' => ['en' => 'A2'], 'type' => 'c', 'parent_id' => $headerA->id, 'order' => 3]);
        $childA1 = $this->makeMenu(['name' => ['en' => 'A1'], 'type' => 'c', 'parent_id' => $headerA->id, 'order' => 2]);
        $this->makeMenu(['name' => ['en' => 'B1'], 'type' => 'c', 'parent_id' => $headerB->id, 'order' => 1]);
        $this->makeMenu(['name' => ['en' => 'A1x'], 'type' => 'c', 'parent_id' => $childA1->id, 'order' => 9]);

        $response = $this->actingAsJwt($admin)
            ->getJson('/api/v1/navigation/lookups/menus?'.http_build_query(['layout' => 'sidebar']))
            ->assertOk();

        $results = collect($response->json('data.results'));

        $this->assertSame(
            ['Header A · h', 'Header B · h'],
            $results->pluck('text')->all(),
        );

        $treeA = $results->firstWhere('id', $headerA->id);
        $this->assertSame(['A1 · c', 'A2 · c'], collect($treeA['children'])->pluck('text')->all());

        $treeB = $results->firstWhere('id', $headerB->id);
        $this->assertSame(['B1 · c'], collect($treeB['children'])->pluck('text')->all());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMenu(array $overrides = []): Menu
    {
        return Menu::query()->create(array_merge([
            'layout' => 'sidebar',
            'name' => ['en' => 'Menu Item'],
            'description' => ['en' => ''],
            'icon' => 'bi bi-circle',
            'route' => null,
            'type' => 'c',
            'order' => 1,
            'audience' => MenuAudience::All,
        ], $overrides));
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);
        $user->assignRole($roleName);

        return $user;
    }

    /**
     * @return array{Authorization: string}
     */
    private function actingAsJwt(User $user): static
    {
        $token = auth('api')->login($user);

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
