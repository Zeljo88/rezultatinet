<?php

namespace App\Services;

use App\Exceptions\ApiFootballBlocked;

class ApiBasketballService
{
    public function getLiveGames(): array
    {
        throw new ApiFootballBlocked('Basketball provider calls are disabled by the sport allowlist.');
    }

    public function getGamesByDate(string $date): array
    {
        throw new ApiFootballBlocked('Basketball provider calls are disabled by the sport allowlist.');
    }
}
