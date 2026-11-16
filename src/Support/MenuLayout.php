<?php

namespace App\Domain\Navigation\Support;

/**
 * Known menu layout identifiers (sidebar, navbar, …).
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
