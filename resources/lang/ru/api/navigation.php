<?php

return [
    'tree_success' => 'Дерево навигации загружено.',
    'layouts_success' => 'Макеты навигации загружены.',
    'list_success' => 'Меню загружены.',
    'show_success' => 'Меню загружено.',
    'trashed_success' => 'Удалённые меню загружены.',
    'created' => 'Меню создано.',
    'updated' => 'Меню обновлено.',
    'deleted' => 'Меню удалено.',
    'restored' => 'Меню восстановлено.',
    'not_found' => 'Меню не найдено.',
    'has_children' => 'У этого меню есть дочерние элементы. Передайте cascade=true, чтобы удалить его вместе с потомками.',
    'invalid_route' => 'Маршрут должен быть зарегистрированным именем маршрута.',
    'position' => [
        'reference_required' => 'reference_id обязателен для позиционирования before/after.',
        'reference_not_in_group' => 'reference_id не входит в ту же группу соседних элементов.',
        'invalid_position' => 'position должно быть before или after.',
        'reference_same_menu' => 'reference_id не может указывать на то же меню.',
        'reference_wrong_group' => 'Ссылочное меню должно иметь те же parent_id и type, что и целевая группа.',
    ],
    'parent_restore_failed' => 'Невозможно восстановить: родительское меню отсутствует или всё ещё удалено. Сначала восстановите родителя.',
];
