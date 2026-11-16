<?php

namespace App\Domain\Navigation\Exceptions;

use App\Exceptions\BusinessRuleException;

class InvalidMenuPositionException extends BusinessRuleException
{
    public function statusCode(): int
    {
        return 422;
    }
}
