<?php

namespace App\Exceptions;

use RuntimeException;

class ApiFootballProviderResponseException extends RuntimeException
{
    public function __construct(public readonly string $classification)
    {
        parent::__construct('API-Football returned an invalid provider response: '.$classification.'.');
    }
}
