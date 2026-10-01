<?php

namespace App\Exceptions;

use RuntimeException;

class FixtureCalendarDisabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Future fixture calendar ingestion is disabled.');
    }
}
