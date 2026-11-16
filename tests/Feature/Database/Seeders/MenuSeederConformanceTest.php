<?php

namespace App\Domain\Navigation\Tests\Feature\Database\Seeders;

use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Http\Requests\Concerns\ValidatesMenuStructure;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Support\MenuLayout;
use App\Support\Localization\LocalePreference;
use App\Support\Navigation\SpaRouteRegistry;
use Database\Seeders\ArchivdRoleSeeder;
use App\Domain\Navigation\Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Conformance test: every seeded menu row must satisfy the same rules the
 * Store/Update FormRequests enforce, so admin-editing a seeded node never 422s.
 */
class MenuSeederConformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ArchivdRoleSeeder::class);
        $this->seed(MenuSeeder::class);
    }

    public function test_seeded_rows_conform_to_menu_validation_rules(): void
    {
        $expectedParentType = ['h' => null, 'p' => 'h', 'c' => 'p'];
        $supportedLocales = LocalePreference::supported();
        $violations = [];

        $menus = Menu::query()
            ->with('roles')
            ->orderBy('layout')
            ->orderBy('parent_id')
            ->orderBy('order')
            ->get();

        $this->assertGreaterThan(0, $menus->count(), 'MenuSeeder seeded no rows.');

        foreach ($menus as $menu) {
            $label = sprintf('[%s] %s (type %s)', $menu->layout, $menu->getTranslation('name', 'en'), $menu->type);

            // type: Rule::in(['h','p','c']) + size:1.
            if (! in_array($menu->type, ['h', 'p', 'c'], true)) {
                $violations[] = "{$label}: invalid type.";
            }

            // layout: Rule::in(MenuLayout::all()).
            if (! in_array($menu->layout, MenuLayout::all(), true)) {
                $violations[] = "{$label}: invalid layout.";
            }

            // Hierarchy: strict h -> p -> c nesting; navbar is a flat
            // template tree whose roots are parentless `p` nodes (no header).
            $expected = $expectedParentType[$menu->type] ?? null;
            $parentType = $menu->parent_id
                ? Menu::query()->whereKey($menu->parent_id)->value('type')
                : null;

            if ($expected === null && $menu->parent_id !== null) {
                $violations[] = "{$label}: header must not have a parent.";
            }

            if ($expected !== null && $menu->parent_id === null
                && ! ($menu->type === 'p' && $menu->layout !== MenuLayout::SIDEBAR)
            ) {
                $violations[] = "{$label}: type must sit under a parent of type {$expected}.";
            }

            if ($expected !== null && $menu->parent_id !== null && $parentType !== $expected) {
                $violations[] = "{$label}: type must sit under a parent of type {$expected} (got {$parentType}).";
            }

            // Layout of the parent must match.
            if ($menu->parent_id !== null) {
                $parentLayout = Menu::query()->whereKey($menu->parent_id)->value('layout');

                if ($parentLayout !== $menu->layout) {
                    $violations[] = "{$label}: parent layout mismatch ({$parentLayout} vs {$menu->layout}).";
                }
            }

            // route: SpaRouteRegistry allowlist (never a URL).
            if ($menu->route !== null && ! SpaRouteRegistry::isValidMenuRoute($menu->route)) {
                $violations[] = "{$label}: route not a valid menu route ({$menu->route}).";
            }

            // hash / icon max length.
            if ($menu->hash !== null && mb_strlen($menu->hash) > 64) {
                $violations[] = "{$label}: hash longer than 64 chars.";
            }

            if ($menu->icon !== null && mb_strlen($menu->icon) > 255) {
                $violations[] = "{$label}: icon longer than 255 chars.";
            }

            // name: locale map with every supported locale non-empty (<= 255).
            $nameTranslations = $menu->getTranslations('name');

            foreach ($nameTranslations as $locale => $text) {
                if (! in_array($locale, $supportedLocales, true)) {
                    $violations[] = "{$label}: name has unsupported locale {$locale}.";
                }

                if (! is_string($text) || trim($text) === '') {
                    $violations[] = "{$label}: name locale {$locale} is empty.";
                } elseif (mb_strlen($text) > 255) {
                    $violations[] = "{$label}: name locale {$locale} longer than 255 chars.";
                }
            }

            foreach ($supportedLocales as $locale) {
                if (! isset($nameTranslations[$locale]) || trim((string) $nameTranslations[$locale]) === '') {
                    $violations[] = "{$label}: name missing locale {$locale}.";
                }
            }

            // Audience integrity: role pivots belong to the `role` audience only,
            // and that audience needs at least one pivot to be reachable.
            if (! in_array($menu->audience->value, MenuAudience::all(), true)) {
                $violations[] = "{$label}: invalid audience ({$menu->audience->value}).";
            }

            if ($menu->audience->usesRoles() && $menu->roles->isEmpty()) {
                $violations[] = "{$label}: role audience needs at least one role.";
            }

            if (! $menu->audience->usesRoles() && $menu->roles->isNotEmpty()) {
                $violations[] = "{$label}: role restrictions require the role audience.";
            }

            // Contiguous sibling order 1..n within (layout, parent_id, type).
            $siblings = Menu::query()
                ->where('layout', $menu->layout)
                ->where('type', $menu->type)
                ->where(function ($query) use ($menu) {
                    $menu->parent_id === null
                        ? $query->whereNull('parent_id')
                        : $query->where('parent_id', $menu->parent_id);
                })
                ->orderBy('order')
                ->pluck('order')
                ->all();

            if ($siblings !== range(1, count($siblings))) {
                $violations[] = "{$label}: sibling orders not contiguous 1..n: [".implode(', ', $siblings).'].';
            }
        }

        $this->assertSame([], $violations, "Seeder rows violate menu validation rules:\n".implode("\n", $violations));
    }

    public function test_seeded_route_names_resolve_in_spa_router_config(): void
    {
        $routes = Menu::query()->whereNotNull('route')->distinct()->pluck('route')->all();

        foreach ($routes as $route) {
            $this->assertTrue(
                SpaRouteRegistry::isValidMenuRoute($route),
                "Route {$route} is not a registered menu route.",
            );

            $this->assertNotContains($route, SpaRouteRegistry::deniedRouteNames());
            $this->assertTrue(
                in_array($route, SpaRouteRegistry::menuRouteNames(), true) || Route::has($route),
                "Route {$route} is neither in config/spa-routes.php 'menu' nor a Laravel route.",
            );
        }
    }
}
