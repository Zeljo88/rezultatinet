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
}
