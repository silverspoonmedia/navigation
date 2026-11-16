<?php

namespace App\Domain\Navigation\Support\ListActions;

use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Models\Menu;
use App\Support\ListActions\QueryRowAction;
use App\Support\ListActions\QueryRowActionBuilder;

final class MenuQueryRowActions implements QueryRowActionBuilder
{
    public function __construct(
        private readonly string $layout,
    ) {}

    /**
     * @return list<QueryRowAction>
     */
    public function resolve(mixed $model, ?User $actor = null): array
    {
        if (! $model instanceof Menu) {
            return [];
        }

        $id = (string) $model->id;
        $name = (string) $model->name;

        return [
            QueryRowAction::nav(
                key: 'edit',
                label: __('ui.actions.edit'),
                icon: 'pencil',
                variant: 'primary',
                route: 'menus.edit',
                params: ['id' => $id],
                query: ['layout' => $this->layout],
            ),
            QueryRowAction::mutate(
                key: 'delete',
                label: __('ui.actions.delete'),
                icon: 'trash3',
                variant: 'danger',
                method: 'DELETE',
                path: 'navigation/menus/'.$id,
                confirm: [
                    'title' => __('list_actions.confirm.delete_named_title', ['name' => $name]),
                    'text' => __('list_actions.confirm.delete_menu', ['name' => $name]),
                ],
            ),
        ];
    }
}
