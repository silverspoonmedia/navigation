<?php

namespace App\Domain\Navigation\Database\Factories;

use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
{
    protected $model = Menu::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'layout' => 'sidebar',
            'name' => fake()->unique()->words(2, true),
            'icon' => null,
            'route' => null,
            'hash' => null,
            'type' => 'c',
            // Parked at NULL by default so multiple factory rows in the same
            // (parent_id, type) group never collide with the unique index.
            // Use the repository when contiguous ordering matters.
            'order' => null,
            'audience' => MenuAudience::Auth,
        ];
    }

    public function header(): static
    {
        return $this->state(fn () => ['type' => 'h', 'parent_id' => null]);
    }

    public function parentItem(Menu|string|null $parent = null): static
    {
        return $this->state(fn () => [
            'type' => 'p',
            'parent_id' => $parent instanceof Menu ? $parent->id : $parent,
        ]);
    }

    public function child(Menu|string|null $parent = null): static
    {
        return $this->state(fn () => [
            'type' => 'c',
            'parent_id' => $parent instanceof Menu ? $parent->id : $parent,
        ]);
    }

    public function authenticated(): static
    {
        return $this->state(fn () => ['audience' => MenuAudience::Auth]);
    }

    public function guestOnly(): static
    {
        return $this->state(fn () => ['audience' => MenuAudience::Guest]);
    }

    /** Visible to guests and signed-in users alike. */
    public function guestVisible(): static
    {
        return $this->state(fn () => ['audience' => MenuAudience::All]);
    }

    /** Signed-in users holding one of the attached roles. */
    public function roleAudience(): static
    {
        return $this->state(fn () => ['audience' => MenuAudience::Role]);
    }
}
