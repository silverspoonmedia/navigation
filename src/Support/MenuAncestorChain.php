<?php

namespace App\Domain\Navigation\Support;

use App\Domain\Navigation\Models\Menu;
use Illuminate\Support\Collection;

/**
 * Resolves the ancestor breadcrumb for a menu (root → parent), including
 * soft-deleted rows so trashed recovery screens stay unambiguous.
 */
class MenuAncestorChain
{
    /**
     * @return Collection<string, Menu>
     */
    public static function catalog(): Collection
    {
        return once(function (): Collection {
            return Menu::withTrashed()
                ->get(['id', 'parent_id', 'name', 'type', 'deleted_at'])
                ->keyBy('id');
        });
    }

    /**
     * Ancestors from root to immediate parent (excludes the menu itself).
     *
     * @return list<array{id: string, name: string, type: string, is_trashed: bool}>
     */
    public static function ancestors(Menu $menu): array
    {
        $catalog = self::catalog();
        $chain = [];
        $parentId = $menu->parent_id;

        while ($parentId !== null) {
            /** @var Menu|null $parent */
            $parent = $catalog->get($parentId);

            if ($parent === null) {
                break;
            }

            $chain[] = [
                'id' => $parent->id,
                'name' => (string) $parent->name,
                'type' => $parent->type,
                'is_trashed' => $parent->trashed(),
            ];

            $parentId = $parent->parent_id;
        }

        return array_reverse($chain);
    }

    /**
     * Human-readable path (root › … › parent) for list screens.
     */
    public static function path(Menu $menu): string
    {
        $labels = array_map(
            fn (array $node): string => $node['name'],
            self::ancestors($menu),
        );

        return implode(' › ', $labels);
    }
}
