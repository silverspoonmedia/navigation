<?php

namespace App\Domain\Navigation\Tests\Unit\Support;

use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Repositories\Contracts\MenuRepositoryInterface;
use App\Domain\Navigation\Support\MenuAncestorChain;
use App\Domain\Navigation\Support\MenuLayout;
use App\Domain\Navigation\Tests\TestCase;

class MenuAncestorChainTest extends TestCase
{
    public function test_ancestors_walk_root_to_parent_including_trashed_rows(): void
    {
        $repo = app(MenuRepositoryInterface::class);

        $header = $repo->createMenu([
            'name' => 'Administration',
            'type' => 'h',
            'layout' => MenuLayout::SIDEBAR,
        ], 'append', null);

        $parent = $repo->createMenu([
            'name' => 'Navigation menu',
            'type' => 'p',
            'layout' => MenuLayout::SIDEBAR,
            'parent_id' => $header->id,
        ], 'append', null);

        $child = $repo->createMenu([
            'name' => 'Menu list',
            'type' => 'c',
            'layout' => MenuLayout::SIDEBAR,
            'parent_id' => $parent->id,
        ], 'append', null);

        $repo->deleteMenu($parent, cascade: true);

        $trashedChild = Menu::onlyTrashed()->whereKey($child->id)->firstOrFail();

        $ancestors = MenuAncestorChain::ancestors($trashedChild);

        $this->assertSame(
            ['Administration', 'Navigation menu'],
            array_column($ancestors, 'name'),
        );
        $this->assertFalse($ancestors[0]['is_trashed']);
        $this->assertTrue($ancestors[1]['is_trashed']);
        $this->assertSame('Administration › Navigation menu', MenuAncestorChain::path($trashedChild));
    }
}
