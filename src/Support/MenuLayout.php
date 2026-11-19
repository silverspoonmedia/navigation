<?php

namespace App\Domain\Navigation\Support;

/**
 * Known menu layout identifiers (sidebar, navbar, …).
 *
 * Package-owned: the Select2 menu lookup moved here with the endpoint
 * (`/api/v1/navigation/lookups/menus`), so the kernel no longer needs a
 * layout catalog contract.
 */
class MenuLayout
{
    public const SIDEBAR = 'sidebar';

    public const NAVBAR = 'navbar';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(config('navigation.layouts', [self::SIDEBAR, self::NAVBAR]));
    }

    public static function default(): string
    {
        return (string) config('navigation.default_layout', self::SIDEBAR);
    }

    public static function isValid(string $layout): bool
    {
        return in_array($layout, self::all(), true);
    }
}
