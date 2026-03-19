<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiTennisService
{
    protected string $baseUrl = 'https://v1.tennis.api-sports.io';

    public function getLiveMatches(): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withHeaders(['x-apisports-key' => config('services.api_football.key')])
                ->timeout(15)
                ->get('/games', ['live' => 'all']);
            if (!$response->successful()) return [];
            return $response->json('response', []);
        } catch (\Exception $e) {
            Log::warning('Tennis API unavailable: ' . $e->getMessage());
            return [];
        }
    }

    public function getMatchesByDate(string $date): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withHeaders(['x-apisports-key' => config('services.api_football.key')])
                ->timeout(15)
                ->get('/games', ['date' => $date]);
            if (!$response->successful()) return [];
            $errors = $response->json('errors', []);
            if (!empty($errors)) return [];
            return $response->json('response', []);
        } catch (\Exception $e) {
            Log::warning('Tennis API unavailable: ' . $e->getMessage());
            return [];
        }
    }
}
