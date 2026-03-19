<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class ApiBasketballService
{
    protected $client;

    public function __construct()
    {
        $this->client = Http::baseUrl('https://v1.basketball.api-sports.io')
            ->withHeaders(['x-apisports-key' => config('services.api_football.key')])
            ->timeout(15);
    }

    /**
     * Free plan doesn't support live=all endpoint.
     * We check status from today's games instead.
     */
    public function getLiveGames(): array
    {
        // Not supported on free plan — returns empty, handled in sync command
        return [];
    }

    public function getGamesByDate(string $date): array
    {
        $response = $this->client->get('/games', ['date' => $date]);
        if (!$response->successful()) return [];
        $errors = $response->json('errors', []);
        if (!empty($errors)) return [];
        return $response->json('response', []);
    }
}
