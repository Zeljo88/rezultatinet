<?php
namespace App\Services;

use App\Models\ApiCallLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiFootballService
{
    protected $client;

    public function __construct()
    {
        $this->client = Http::baseUrl('https://v3.football.api-sports.io')
            ->withHeaders([
                'X-RapidAPI-Key'  => config('services.api_football.key'),
                'X-RapidAPI-Host' => 'v3.football.api-sports.io',
            ])
            ->timeout(15)
            ->retry(2, 500);
    }


    /**
     * Check if daily API quota has been reached.
     * Hard limit: 7000 calls/day (500 buffer for safety).
     */
    protected function quotaExceeded(): bool
    {
        $count = ApiCallLog::getTodayCount();
        if ($count >= 7000) {
            Log::warning('ApiFootballService: daily quota limit reached (' . $count . '/7000), skipping call.');
            return true;
        }
        return false;
    }

    public function getLiveFixtures(): array
    {
        if ($this->quotaExceeded()) return [];

        $response = $this->client->get('/fixtures', ['live' => 'all']);
        if (!$response->successful()) return [];
        return $response->json('response', []);
    }

    public function getTodayFixtures(): array
    {
        if ($this->quotaExceeded()) return [];

        $response = $this->client->get('/fixtures', ['date' => now()->format('Y-m-d')]);
        if (!$response->successful()) return [];
        return $response->json('response', []);
    }

    public function getFixtureById(int $apiFixtureId): array
    {
        if ($this->quotaExceeded()) return [];

        $response = $this->client->get('/fixtures', ['id' => $apiFixtureId]);
        if (!$response->successful()) return [];
        return $response->json('response', [])[0] ?? [];
    }

    public function getTopScorers(int $leagueId, int $season): array
    {
        if ($this->quotaExceeded()) return [];

        $response = $this->client->get('/players/topscorers', [
            'league' => $leagueId,
            'season' => $season,
        ]);
        if (!$response->successful()) return [];
        return $response->json('response', []);
    }

    public function getTopAssists(int $leagueId, int $season): array
    {
        if ($this->quotaExceeded()) return [];

        $response = $this->client->get('/players/topassists', [
            'league' => $leagueId,
            'season' => $season,
        ]);
        if (!$response->successful()) return [];
        return $response->json('response', []);
    }

    public function getLineups(int $apiFixtureId): array
    {
        if ($this->quotaExceeded()) return [];

        $response = $this->client->get('/fixtures/lineups', ['fixture' => $apiFixtureId]);
        if (!$response->successful()) return [];
        return $response->json('response', []);
    }
}
