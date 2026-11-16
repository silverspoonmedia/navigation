<?php

namespace App\Domain\Navigation\Http\Resources;

use App\Domain\Navigation\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Menu
 */
class MenuListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'layout' => $this->layout,
            'name' => $this->name,
            'description' => $this->description,
            'name_translations' => $this->getTranslations('name'),
            'description_translations' => $this->getTranslations('description'),
            'icon' => $this->icon,
            'route' => $this->route,
            'hash' => $this->hash,
            'type' => $this->type,
            'order' => $this->order,
            'audience' => $this->audience->value,
            'role_ids' => $this->roles->pluck('id')->values()->all(),
            'user_include_ids' => $this->includedUsers->pluck('id')->values()->all(),
            'user_exclude_ids' => $this->excludedUsers->pluck('id')->values()->all(),
            'children_count' => $this->whenCounted('children'),
        ];
    }
}
