<?php

namespace App\Domain\Navigation\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Menu tree may have changed — clients soft-refetch the navigation tree.
 */
class MenuTreeChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $layout,
        public readonly string $action,
        public readonly ?string $targetUserId = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('navigation.menus')];
    }

    public function broadcastAs(): string
    {
        return 'menu.tree.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'layout' => $this->layout,
            'action' => $this->action,
            'target_user_id' => $this->targetUserId,
        ];
    }
}
