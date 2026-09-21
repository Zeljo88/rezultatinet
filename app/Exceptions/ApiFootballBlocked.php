<?php

namespace App\Exceptions;

use App\Support\ApiFootballBlockReason;
use RuntimeException;
use Throwable;

class ApiFootballBlocked extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ApiFootballBlockReason $reason = ApiFootballBlockReason::OtherQuota,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
