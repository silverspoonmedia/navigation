<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Menu layout identifiers
    |--------------------------------------------------------------------------
    |
    | Each dynamic navigation tree is scoped to a layout key. The sidebar and
    | navbar partials load only menus that belong to their respective layout.
    | Add keys here when introducing new shell regions that consume menus.
    |
    */

    'layouts' => [
        'sidebar',
        'navbar',
    ],

    'default_layout' => 'sidebar',

];
