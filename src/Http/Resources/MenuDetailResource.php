<?php

namespace App\Domain\Navigation\Http\Resources;

use App\Domain\Navigation\Models\Menu;
use App\Http\Resources\Concerns\FormatsPresentation;
use Illuminate\Http\Request;

/**
 * @mixin Menu
 */
class MenuDetailResource extends MenuListResource
{
    use FormatsPresentation;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            ...$this->dateFields('created_at', $this->created_at),
            ...$this->dateFields('updated_at', $this->updated_at),
        ]);
    }
}
