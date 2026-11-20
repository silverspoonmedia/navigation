<?php

namespace App\Domain\Navigation\Tests\Unit\Support;

use App\Domain\Navigation\Support\MenuLayout;
use App\Domain\Navigation\Tests\TestCase;

class MenuLayoutTest extends TestCase
{
    public function test_known_layouts_are_configured(): void
    {
        $this->assertContains(MenuLayout::SIDEBAR, MenuLayout::all());
        $this->assertContains(MenuLayout::NAVBAR, MenuLayout::all());
        $this->assertSame('sidebar', MenuLayout::default());
    }
}
