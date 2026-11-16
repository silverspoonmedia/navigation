<?php

namespace App\Domain\Navigation\Tests\Unit\Enums;

use App\Domain\Navigation\Enums\MenuAudience;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MenuAudienceTest extends TestCase
{
    public function test_all_lists_every_case_value(): void
    {
        $this->assertSame(['all', 'auth', 'role', 'guest'], MenuAudience::all());
    }

    public function test_try_from_rejects_unknown_values(): void
    {
        $this->assertNull(MenuAudience::tryFrom('nobody'));
        $this->assertNull(MenuAudience::tryFrom(''));
        $this->assertSame(MenuAudience::Auth, MenuAudience::tryFrom('auth'));
    }

    /**
     * @return array<string, array{MenuAudience, bool, bool, bool, bool}>
     */
    public static function audiencePredicates(): array
    {
        return [
            'all' => [MenuAudience::All, true, false, false, false],
            'auth' => [MenuAudience::Auth, false, true, false, false],
            'role' => [MenuAudience::Role, false, true, true, false],
            'guest' => [MenuAudience::Guest, true, false, false, true],
        ];
    }

    /**
     * @dataProvider audiencePredicates
     */
    #[DataProvider('audiencePredicates')]
    public function test_predicates_match_the_documented_matrix(
        MenuAudience $audience,
        bool $allowsGuests,
        bool $requiresUser,
        bool $usesRoles,
        bool $isGuestOnly,
    ): void {
        $this->assertSame($allowsGuests, $audience->allowsGuests());
        $this->assertSame($requiresUser, $audience->requiresUser());
        $this->assertSame($usesRoles, $audience->usesRoles());
        $this->assertSame($isGuestOnly, $audience->isGuestOnly());
    }

    public function test_only_role_audience_uses_roles(): void
    {
        $usingRoles = array_values(array_filter(
            MenuAudience::cases(),
            fn (MenuAudience $audience): bool => $audience->usesRoles(),
        ));

        $this->assertSame([MenuAudience::Role], $usingRoles);
    }
}
