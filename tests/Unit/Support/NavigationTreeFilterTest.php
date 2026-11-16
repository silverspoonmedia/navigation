<?php

namespace App\Domain\Navigation\Tests\Unit\Support;

use App\Domain\Navigation\Support\NavigationTreeFilter;
use PHPUnit\Framework\TestCase;

class NavigationTreeFilterTest extends TestCase
{
    private NavigationTreeFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new NavigationTreeFilter;
    }

    public function test_empty_query_returns_original_tree(): void
    {
        $tree = [$this->node('Reports', 'h', [$this->node('Users', 'c')])];

        $this->assertSame($tree, $this->filter->filter($tree, ''));
        $this->assertSame($tree, $this->filter->filter($tree, '   '));
    }

    public function test_matching_leaf_keeps_ancestor_headers_and_parents(): void
    {
        $tree = [
            $this->node('Admin', 'h', [
                $this->node('Users', 'p', [
                    $this->node('User list', 'c', route: 'users.index'),
                    $this->node('Roles', 'c'),
                ]),
            ]),
        ];

        $filtered = $this->filter->filter($tree, 'user list');

        $this->assertSame('Admin', $filtered[0]['name']);
        $this->assertSame('Users', $filtered[0]['children'][0]['name']);
        $this->assertSame('User list', $filtered[0]['children'][0]['children'][0]['name']);
        $this->assertCount(1, $filtered[0]['children'][0]['children']);
    }

    public function test_non_matching_query_returns_empty_tree(): void
    {
        $tree = [$this->node('Dashboard', 'c')];

        $this->assertSame([], $this->filter->filter($tree, 'zzzz'));
    }

    public function test_search_is_case_insensitive_and_partial(): void
    {
        $tree = [$this->node('Pengaturan', 'c')];

        $filtered = $this->filter->filter($tree, 'ATUR');

        $this->assertCount(1, $filtered);
        $this->assertSame('Pengaturan', $filtered[0]['name']);
    }

    public function test_search_matches_route_name(): void
    {
        $tree = [$this->node('Hidden label', 'c', route: 'users.index')];

        $filtered = $this->filter->filter($tree, 'users.index');

        $this->assertCount(1, $filtered);
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function node(string $name, string $type, array $children = [], ?string $route = null): array
    {
        return [
            'id' => '019e8766-857b-7259-a580-a21e1ddfa27e',
            'name' => $name,
            'description' => null,
            'icon' => null,
            'route' => $route,
            'type' => $type,
            'order' => 1,
            'children' => $children,
        ];
    }
}
