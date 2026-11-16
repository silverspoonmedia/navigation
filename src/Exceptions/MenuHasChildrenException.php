<?php

namespace App\Domain\Navigation\Exceptions;

use App\Exceptions\BusinessRuleException;

class MenuHasChildrenException extends BusinessRuleException
{
    public function __construct()
    {
        parent::__construct(__('api/navigation.has_children'));
    }

    public function statusCode(): int
    {
        return 409;
    }
}
