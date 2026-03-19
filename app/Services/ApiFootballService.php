<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

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

    public function getLiveFixtures(): array
    {
        $response = $this->client->get('/fixtures', ['live' => 'all']);
        if (!$response->successful()) return [];
        return $response->json('response', []);
    }

    public function getTodayFixtures(): array
    {
        $response = $this->client->get('/fixtures', ['date' => now()->format('Y-m-d')]);
        if (!$response->successful()) return [];
        return $response->json('response', []);
    }

    public function getFixtureById(int $apiFixtureId): array
    {
        $response = $this->client->get('/fixtures', ['id' => $apiFixtureId]);
        if (!$response->successful()) return [];
        return $response->json('response', [])[0] ?? [];
    }

    public function getTopScorers(int $leagueId, int $season): array
    {
        $response = $this->client->get('/players/topscorers', [
            'league' => $leagueId,
            'season' => $season,
        ]);
        if (!$response->successful()) return [];
        return $response->json('response', []);
    }

    public function getLineups(int $apiFixtureId): array
    {
        $response = $this->client->get('/fixtures/lineups', ['fixture' => $apiFixtureId]);
        if (!$response->successful()) return [];
        return $response->json('response', []);
    }
}
