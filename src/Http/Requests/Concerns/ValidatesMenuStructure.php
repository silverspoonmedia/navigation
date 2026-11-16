<?php

namespace App\Domain\Navigation\Http\Requests\Concerns;

use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Support\MenuLayout;
use Illuminate\Validation\Validator;

/**
 * Shared menu hierarchy rules:
 *  - structural integrity: header(h) is a root, parent(p) sits under a header,
 *    child(c) sits under a parent (strict h -> p -> c nesting); flat template
 *    layouts (navbar) allow parentless `p` roots;
 *  - cycle safety: a menu may not be re-parented under one of its descendants;
 *  - audience integrity: role restrictions belong to the `role` audience only.
 */
trait ValidatesMenuStructure
{
    /**
     * Each type must sit directly under a parent exactly one rank higher.
     * Exception: the navbar layout is a flat template tree whose roots are
     * parentless `p` nodes (no headers); only the sidebar enforces a
     * header root.
     */
    private const EXPECTED_PARENT_TYPE = ['h' => null, 'p' => 'h', 'c' => 'p'];

    protected function assertMenuStructure(Validator $validator, string $type, ?string $parentId, ?string $layout = null): void
    {
        if (! array_key_exists($type, self::EXPECTED_PARENT_TYPE)) {
            return;
        }

        $expected = self::EXPECTED_PARENT_TYPE[$type];

        if ($expected === null) {
            if ($parentId !== null) {
                $validator->errors()->add('parent', 'A header (type "h") is a root item and cannot have a parent.');
            }

            return;
        }

        if ($parentId === null) {
            if ($type === 'p' && $layout !== null && $layout !== MenuLayout::SIDEBAR) {
                return;
            }

            $validator->errors()->add('parent', "A menu of type \"{$type}\" requires a parent of type \"{$expected}\".");

            return;
        }

        $parentType = Menu::query()->whereKey($parentId)->value('type');

        if ($parentType !== $expected) {
            $validator->errors()->add('parent', "A menu of type \"{$type}\" must be placed under a parent of type \"{$expected}\".");
        }
    }

    protected function assertParentLayoutMatches(Validator $validator, string $layout, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parentLayout = Menu::query()->whereKey($parentId)->value('layout');

        if ($parentLayout !== null && $parentLayout !== $layout) {
            $validator->errors()->add('parent', 'The parent menu must belong to the same layout as this menu.');
        }
    }

    /**
     * Reject audience/role combinations that would make the stored audience lie
     * about the effective visibility. Role restrictions only make sense for the
     * signed-in-with-roles audience, and that audience needs at least one role.
     *
     * A null audience means the submitted value was not a valid case — the
     * `audience` Enum rule already reports that, so no second error is stacked.
     */
    protected function assertAudienceCompatible(Validator $validator, ?MenuAudience $audience, bool $hasRoles): void
    {
        if ($audience === null) {
            return;
        }

        if ($audience->usesRoles() && ! $hasRoles) {
            $validator->errors()->add('roles', 'Select at least one role for the signed-in-with-roles audience.');
        }

        if (! $audience->usesRoles() && $hasRoles) {
            $validator->errors()->add('audience', 'Role restrictions require the signed-in-with-roles audience.');
        }
    }

    /**
     * Audience this request would persist: the submitted value, or `$fallback`
     * when the key is absent. Null means the submitted value is not a valid
     * case (already reported by the `audience` Enum rule).
     */
    protected function effectiveAudience(MenuAudience $fallback): ?MenuAudience
    {
        if (! $this->has('audience')) {
            return $fallback;
        }

        return MenuAudience::tryFrom((string) $this->input('audience'));
    }

    protected function assertParentIsNotDescendant(Validator $validator, string $menuId, ?string $parentId): void
    {
        if ($parentId === null || $parentId === $menuId) {
            return;
        }

        if (in_array($parentId, $this->descendantIds($menuId), true)) {
            $validator->errors()->add('parent', 'A menu cannot be moved under one of its own descendants.');
        }
    }

    /**
     * @return list<string>
     */
    private function descendantIds(string $menuId): array
    {
        $childrenByParent = [];
        foreach (Menu::query()->get(['id', 'parent_id']) as $menu) {
            $childrenByParent[$menu->parent_id][] = $menu->id;
        }

        $descendants = [];
        $stack = [$menuId];

        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($childrenByParent[$current] ?? [] as $childId) {
                $descendants[] = $childId;
                $stack[] = $childId;
            }
        }

        return $descendants;
    }
}
