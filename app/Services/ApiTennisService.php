<?php

namespace App\Services;

use App\Exceptions\ApiFootballBlocked;

class ApiTennisService
{
    public function getLiveMatches(): array
    {
        throw new ApiFootballBlocked('Tennis provider calls are disabled by the sport allowlist.');
    }

    public function getMatchesByDate(string $date): array
    {
        throw new ApiFootballBlocked('Tennis provider calls are disabled by the sport allowlist.');
    }
}
