<?php

namespace App\Domain\Navigation\Tests\Feature;

use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Repositories\Eloquent\MenuRepository;
use App\Support\DomainAccess\DomainAccessService;
use App\Support\DomainAccess\Enums\DomainVisibility;
use App\Support\DomainAccess\ProductNames;
use App\Support\Product\Services\ProductActivationService;
use Database\Seeders\DomainAccessSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A product domain's menu nodes must disappear when the domain's global
 * visibility is Private and the viewer lacks access. Public visibility keeps
 * the existing per-user activation gate intact.
 *
 * Menu route archivd.index is bound to the Archivd product via
 * config/products.php menu_routes.
 */
class PrivateDomainMenuVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_KEY = 'Archivd';

    private const ROUTE = 'archivd.index';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(DomainAccessSeeder::class);

        $repository = app(MenuRepository::class);
        $header = $repository->createMenu(['name' => 'Files Header', 'type' => 'h', 'audience' => MenuAudience::All], 'append', null);
        $repository->createMenu([
            'name' => 'Files',
            'type' => 'c',
            'route' => self::ROUTE,
            'parent_id' => $header->id,
            'audience' => MenuAudience::All,
        ], 'append', null);
    }

    public function test_public_domain_shows_menu_for_activated_user(): void
    {
        app(DomainAccessService::class)->setVisibility(self::PRODUCT_KEY, DomainVisibility::Public);

        $user = $this->activatedUser('User');

        $this->assertContains('Files', $this->treeNames($user));
    }

    public function test_private_domain_hides_menu_and_prunes_header_for_plain_user(): void
    {
        app(DomainAccessService::class)->setVisibility(self::PRODUCT_KEY, DomainVisibility::Private);

        $user = $this->activatedUser('User');

        $names = $this->treeNames($user);
        $this->assertNotContains('Files', $names);
        $this->assertNotContains('Files Header', $names);
    }

    public function test_private_domain_shows_menu_for_root(): void
    {
        app(DomainAccessService::class)->setVisibility(self::PRODUCT_KEY, DomainVisibility::Private);

        $user = $this->activatedUser('Root', 'root@example.com');

        $this->assertContains('Files', $this->treeNames($user));
    }

    public function test_private_domain_shows_menu_for_beta_tester(): void
    {
        app(DomainAccessService::class)->setVisibility(self::PRODUCT_KEY, DomainVisibility::Private);

        $user = $this->activatedUser('User', 'beta@example.com');
        $user->assignRole(ProductNames::betaRole(self::PRODUCT_KEY));

        $this->assertContains('Files', $this->treeNames($user->fresh()));
    }

    public function test_private_domain_hides_menu_for_guest(): void
    {
        app(DomainAccessService::class)->setVisibility(self::PRODUCT_KEY, DomainVisibility::Private);

        $this->assertNotContains('Files', $this->treeNames(null));
    }

    private function activatedUser(string $role, string $email = 'user@example.com'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role);
        app(ProductActivationService::class)->activateImplicit($user, self::PRODUCT_KEY);

        return $user->fresh();
    }

    /**
     * Resolve a fresh repository so DomainAccessService request-scoped cache
     * reflects the visibility set in the current test.
     *
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
        $walk(app(MenuRepository::class)->navigationTree($user, 'sidebar'));

        return $names;
    }
}
