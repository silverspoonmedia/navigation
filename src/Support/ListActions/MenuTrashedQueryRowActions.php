<?php

namespace App\Domain\Navigation\Support\ListActions;

use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Models\Menu;
use App\Support\ListActions\QueryRowAction;
use App\Support\ListActions\QueryRowActionBuilder;

final class MenuTrashedQueryRowActions implements QueryRowActionBuilder
{
    /**
     * @return list<QueryRowAction>
     */
    public function resolve(mixed $model, ?User $actor = null): array
    {
        if (! $model instanceof Menu || $actor === null || ! $actor->can('restore menu')) {
            return [];
        }

        return [
            QueryRowAction::mutate(
                key: 'restore',
                label: __('ui.datatable.restore'),
                icon: 'arrow-counterclockwise',
                variant: 'success',
                method: 'POST',
                path: 'navigation/menus/'.$model->id.'/restore',
            ),
        ];
    }
}
