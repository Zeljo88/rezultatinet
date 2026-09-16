<?php

namespace App\Support;

use App\Models\BasketballGame;
use App\Models\Fixture;
use App\Models\TennisMatch;
use Illuminate\Support\Collection;

class SeoHubData
{
    public static function footballWindows(?int $leagueId = null): array
    {
        $base = Fixture::query()
            ->with(['homeTeam', 'awayTeam', 'score', 'league'])
            ->when($leagueId, fn ($query) => $query->where('league_id', $leagueId));

        $live = (clone $base)->live()->whereDate('kick_off', today())->orderBy('kick_off')->take(6)->get();
        $upcoming = (clone $base)->whereIn('status_short', ['NS', 'TBD'])
            ->where('kick_off', '>=', now())->orderBy('kick_off')->take(6)->get();
        $recent = (clone $base)->whereIn('status_short', ['FT', 'AET', 'PEN'])
            ->where('kick_off', '<=', now())->orderByDesc('kick_off')->take(6)->get();

        return [
            'live' => self::mapFootball($live),
            'upcoming' => self::mapFootball($upcoming),
            'recent' => self::mapFootball($recent),
        ];
    }

    public static function basketballWindows(?string $leagueName = null): array
    {
        $base = BasketballGame::query()->when(
            $leagueName,
            fn ($query) => $query->where('league_name', $leagueName),
        );
        $liveStatuses = ['Q1', 'Q2', 'Q3', 'Q4', 'HT', 'OT', 'LIVE', 'BP', 'BT'];

        return [
            'live' => self::mapBasketball((clone $base)->whereIn('status_short', $liveStatuses)->whereDate('game_date', today())->orderBy('game_date')->take(6)->get()),
            'upcoming' => self::mapBasketball((clone $base)->whereIn('status_short', ['NS', 'TBD'])->where('game_date', '>=', now())->orderBy('game_date')->take(6)->get()),
            'recent' => self::mapBasketball((clone $base)->whereIn('status_short', ['FT', 'AOT'])->where('game_date', '<=', now())->orderByDesc('game_date')->take(6)->get()),
        ];
    }

    public static function tennisWindows(): array
    {
        $base = TennisMatch::query();
        $liveStatuses = ['In Play', '1st Set', '2nd Set', '3rd Set', '4th Set', '5th Set', 'Break Time'];
        $finished = ['Finished', 'Retired', 'Walkover', 'Default', 'FT'];

        return [
            'live' => self::mapTennis((clone $base)->whereIn('status', $liveStatuses)->whereDate('match_date', today())->orderBy('match_date')->take(6)->get()),
            'upcoming' => self::mapTennis((clone $base)->whereIn('status', ['Not Started', 'NS'])->where('match_date', '>=', now())->orderBy('match_date')->take(6)->get()),
            'recent' => self::mapTennis((clone $base)->whereIn('status', $finished)->where('match_date', '<=', now())->orderByDesc('match_date')->take(6)->get()),
        ];
    }

    private static function mapFootball(Collection $fixtures): array
    {
        return $fixtures->map(function (Fixture $fixture) {
            $slug = MatchRoute::slugFor($fixture);

            return [
                'date' => $fixture->kick_off,
                'status' => $fixture->status_short,
                'home' => $fixture->homeTeam?->name,
                'away' => $fixture->awayTeam?->name,
                'home_slug' => $fixture->homeTeam?->slug,
                'away_slug' => $fixture->awayTeam?->slug,
                'home_score' => $fixture->score?->home_fulltime ?? $fixture->score?->goals_home,
                'away_score' => $fixture->score?->away_fulltime ?? $fixture->score?->goals_away,
                'league' => $fixture->league?->name,
                'url' => $slug ? url('/utakmica/' . $slug) : null,
            ];
        })->filter(fn ($row) => $row['home'] && $row['away'])->values()->all();
    }

    private static function mapBasketball(Collection $games): array
    {
        return $games->map(fn (BasketballGame $game) => [
            'date' => $game->game_date,
            'status' => $game->status_short,
            'home' => $game->home_team,
            'away' => $game->away_team,
            'home_score' => $game->home_score,
            'away_score' => $game->away_score,
            'league' => $game->league_name,
            'url' => null,
        ])->filter(fn ($row) => $row['home'] && $row['away'])->values()->all();
    }

    private static function mapTennis(Collection $matches): array
    {
        return $matches->map(fn (TennisMatch $match) => [
            'date' => $match->match_date,
            'status' => $match->status,
            'home' => $match->player_home,
            'away' => $match->player_away,
            'score' => $match->score,
            'league' => $match->tournament_name,
            'url' => null,
        ])->filter(fn ($row) => $row['home'] && $row['away'])->values()->all();
    }
}
