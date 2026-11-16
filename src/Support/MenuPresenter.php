<?php

namespace App\Domain\Navigation\Support;

use App\Domain\Navigation\Http\Resources\MenuDetailResource;
use App\Domain\Navigation\Http\Resources\MenuListResource;
use App\Domain\Navigation\Http\Resources\MenuTrashedResource;
use App\Domain\Navigation\Models\Menu;

class MenuPresenter
{
    /**
     * Flat admin row including RBAC assignments.
     *
     * @return array<string, mixed>
     */
    public static function presentAdminRow(Menu $menu): array
    {
        return (new MenuListResource($menu))->resolve(request());
    }

    /**
     * @param  iterable<int, Menu>  $menus
     * @return list<array<string, mixed>>
     */
    public static function presentAdminCollection(iterable $menus): array
    {
        return MenuListResource::collection($menus)->resolve(request());
    }

    /**
     * Admin row plus the deletion timestamp, for the trashed listing.
     *
     * @return array<string, mixed>
     */
    public static function presentTrashedRow(Menu $menu): array
    {
        return (new MenuTrashedResource($menu))->resolve(request());
    }

    /**
     * @param  iterable<int, Menu>  $menus
     * @return list<array<string, mixed>>
     */
    public static function presentTrashedCollection(iterable $menus): array
    {
        return MenuTrashedResource::collection($menus)->resolve(request());
    }

    /**
     * Single-record detail (used after create/update/show).
     *
     * @return array<string, mixed>
     */
    public static function presentDetail(Menu $menu): array
    {
        return (new MenuDetailResource($menu))->resolve(request());
    }
}
