<?php

namespace App\Domain\Navigation\Repositories\Contracts;

use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Models\Menu;
use App\Support\Navigation\PresentedTreeEpoch;

interface MenuRepositoryInterface extends PresentedTreeEpoch
{
    /**
     * Build the auth-aware navigation tree for the given user (null = guest).
     *
     * @return list<array<string, mixed>>
     */
    public function navigationTree(?User $user, string $layout): array;

    /**
     * Hierarchically sorted flat list for admin/management screens.
     *
     * @return list<Menu>
     */
    public function adminFlatList(?string $loadType, ?string $layout = null): array;

    public function read(string $id): Menu;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMenu(array $data, ?string $position, ?string $referenceId): Menu;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMenu(Menu $menu, array $data): Menu;

    public function updateMenuPosition(Menu $menu, string $position, string $referenceId): Menu;

    /**
     * Soft-delete a menu. When it has descendants, $cascade must be true to
     * soft-delete the whole subtree; otherwise the call is rejected.
     */
    public function deleteMenu(Menu $menu, bool $cascade = false): void;

    /**
     * Restore a soft-deleted menu together with its trashed subtree, re-appending
     * each restored row to the tail of its sibling group. Rejected when the
     * parent is still trashed (would re-orphan the branch).
     */
    public function restoreMenu(Menu $menu): void;

    /**
     * Soft-deleted menus, optionally filtered by type, newest deletion first.
     *
     * @return list<Menu>
     */
    public function trashedList(?string $loadType, ?string $layout = null): array;

    /**
     * Forget the cached navigation snapshot and bump presented-tree epoch
     * (e.g. after a role rename or menu mutation).
     */
    public function flushNavigationCache(): void;

    /**
     * Drop presented navigation trees for one viewer under the current epoch
     * (e.g. after product activation or role changes for that user).
     */
    public function forgetPresentedTreeFor(?User $user): void;

    /**
     * @see forgetPresentedTreeFor()
     */
    public function forgetPresentedTreeForUserId(string $userId): void;

    /**
     * Invalidate all presented trees without rebuilding the menu row snapshot
     * (e.g. Domain Access visibility changes).
     */
    public function bumpPresentedTreeEpoch(): void;

    /**
     * @param  list<string>  $idsOrNames
     */
    public function syncRoles(Menu $menu, array $idsOrNames): void;

    /**
     * @param  list<string>  $userIds
     */
    public function syncUserIncludes(Menu $menu, array $userIds): void;

    /**
     * @param  list<string>  $userIds
     */
    public function syncUserExcludes(Menu $menu, array $userIds): void;
}
