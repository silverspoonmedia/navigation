<?php

return [
    'tree_success' => 'Navigation tree retrieved successfully.',
    'layouts_success' => 'Navigation layouts retrieved successfully.',
    'list_success' => 'Menus retrieved successfully.',
    'show_success' => 'Menu retrieved successfully.',
    'trashed_success' => 'Trashed menus retrieved successfully.',
    'created' => 'Menu created successfully.',
    'updated' => 'Menu updated successfully.',
    'deleted' => 'Menu deleted successfully.',
    'restored' => 'Menu restored successfully.',
    'not_found' => 'Menu not found.',
    'has_children' => 'This menu has child items. Pass cascade=true to delete it together with its descendants.',
    'invalid_route' => 'The route must be a registered route name.',
    'position' => [
        'reference_required' => 'reference_id is required for before/after positioning.',
        'reference_not_in_group' => 'reference_id is not in the same sibling group.',
        'invalid_position' => 'position must be before or after.',
        'reference_same_menu' => 'reference_id cannot be the same menu.',
        'reference_wrong_group' => 'reference menu must share parent_id and type with the target group.',
    ],
    'parent_restore_failed' => 'Cannot restore: the parent menu is missing or still deleted. Restore the parent first.',
];
