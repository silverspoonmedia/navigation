<?php

namespace App\Domain\Navigation\Listeners;

use App\Domain\Navigation\Repositories\Contracts\MenuRepositoryInterface;
use App\Support\Product\Events\ProductActivationStateChanged;

/**
 * Drop per-viewer presented navigation trees when activation state changes.
 */
final class ForgetPresentedNavigationTree
{
    public function __construct(
        private readonly MenuRepositoryInterface $menus,
    ) {}

    public function handle(ProductActivationStateChanged $event): void
    {
        $this->menus->forgetPresentedTreeForUserId((string) $event->activation->user_id);
    }
}
