<?php

namespace App\Domain\Navigation\Enums;

/**
 * Who may see a menu node.
 *
 * One axis replaces the old `is_authenticate` / `is_guest_only` boolean pair
 * plus the implicit interaction with role restrictions:
 *
 *  - `all`   — guests and signed-in users;
 *  - `auth`  — signed-in users only, no role restriction;
 *  - `role`  — signed-in users holding one of the attached roles;
 *  - `guest` — guests only.
 *
 * `role` is the only case that carries role pivots; the others must have none,
 * so the audience value never lies about the effective visibility.
 */
enum MenuAudience: string
{
    case All = 'all';

    case Auth = 'auth';

    case Role = 'role';

    case Guest = 'guest';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Guests pass the audience check. */
    public function allowsGuests(): bool
    {
        return $this === self::All || $this === self::Guest;
    }

    /** A signed-in user is required (roles cannot apply to guests). */
    public function requiresUser(): bool
    {
        return $this === self::Auth || $this === self::Role;
    }

    /** Role pivots are meaningful — and mandatory — for this audience. */
    public function usesRoles(): bool
    {
        return $this === self::Role;
    }

    /** Only guests see the node; signed-in users never do. */
    public function isGuestOnly(): bool
    {
        return $this === self::Guest;
    }
}
