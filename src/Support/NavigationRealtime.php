<?php

namespace App\Domain\Navigation\Support;

use App\Domain\Navigation\Events\MenuTreeChanged;
use App\Support\Broadcasting\BroadcastConnection;

/**
 * Fire Navigation realtime events after successful mutations.
 *
 * Gated on {@see BroadcastConnection::isConfigured()} only.
 */
class NavigationRealtime
{
    public function enabled(): bool
    {
        return BroadcastConnection::isConfigured();
    }

    public function menuTreeChanged(string $layout, string $action): void
    {
        if (! $this->enabled()) {
            return;
        }

        event(new MenuTreeChanged($layout, $action));
    }

    /**
     * Targeted tree refresh for one viewer (e.g. a role assignment changed).
     * Shared channel; the SPA filters by target_user_id.
     */
    public function userTreeChanged(string $userId, string $action = 'membership'): void
    {
        if (! $this->enabled()) {
            return;
        }

        event(new MenuTreeChanged('', $action, $userId));
    }
}
