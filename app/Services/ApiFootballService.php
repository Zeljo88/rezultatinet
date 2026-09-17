<?php

namespace App\Services;

use App\Services\ApiFootball\ApiFootballGateway;

class ApiFootballService
{
    public function __construct(private readonly ApiFootballGateway $gateway) {}

    public function getLiveFixtures(string $caller = 'FetchLiveFixtures'): array
    {
        return $this->gateway->get('/fixtures', ['live' => 'all'], 'live', $caller);
    }

    public function getFixturesByDate(string $date, string $caller = 'SyncFixtures'): array
    {
        return $this->gateway->get('/fixtures', ['date' => $date], 'backfill', $caller);
    }

    public function getTodayFixtures(string $caller = 'ApiFootballService'): array
    {
        return $this->getFixturesByDate(now('UTC')->format('Y-m-d'), $caller);
    }

    public function getFixtureById(int $id, string $caller = 'fixture_repair', string $class = 'fixture_repair'): array
    {
        return $this->gateway->get('/fixtures', ['id' => $id], $class, $caller)[0] ?? [];
    }

    public function getLineups(int $id, string $caller = 'FetchFixtureLineups'): array
    {
        return $this->gateway->get('/fixtures/lineups', ['fixture' => $id], 'lineups', $caller);
    }

    public function getStandings(int $league, int $season, string $caller = 'SyncStandings'): array
    {
        return $this->gateway->get('/standings', compact('league', 'season'), 'standings', $caller);
    }

    public function getLeagues(string $caller = 'SyncLeagues'): array
    {
        return $this->gateway->get('/leagues', ['current' => 'true'], 'manual', $caller);
    }

    public function getFixtureEvents(int $id, string $caller = 'SyncFixtureEvents'): array
    {
        return $this->gateway->get('/fixtures/events', ['fixture' => $id], 'events', $caller);
    }

    public function getTopScorers(int $leagueId, int $season, string $caller = 'SyncTopScorers'): array
    {
        return $this->gateway->get('/players/topscorers', ['league' => $leagueId, 'season' => $season], 'scorers', $caller);
    }

    public function getTopAssists(int $leagueId, int $season, string $caller = 'SyncTopScorers'): array
    {
        return $this->gateway->get('/players/topassists', ['league' => $leagueId, 'season' => $season], 'scorers', $caller);
    }
}
