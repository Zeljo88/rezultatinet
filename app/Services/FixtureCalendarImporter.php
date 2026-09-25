<?php

namespace App\Services;

use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Models\League;
use App\Models\Team;
use App\Support\FootballFixtureStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FixtureCalendarImporter
{
    /** Existing match state owned by live polling/finalization must stay untouched. */
    private const PROTECTED_EXISTING_STATUSES = [
        '1H', '2H', 'HT', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE',
        'FT', 'AET', 'PEN', 'AWD', 'WO', 'CANC', 'ABD',
    ];

    /** @return array{upserted: int, protected: int, skipped: int} */
    public function import(array $payload): array
    {
        $result = ['upserted' => 0, 'protected' => 0, 'skipped' => 0];

        foreach ($payload as $data) {
            $fixtureId = filter_var($data['fixture']['id'] ?? null, FILTER_VALIDATE_INT);
            $leagueApiId = filter_var($data['league']['id'] ?? null, FILTER_VALIDATE_INT);
            $homeApiId = filter_var($data['teams']['home']['id'] ?? null, FILTER_VALIDATE_INT);
            $awayApiId = filter_var($data['teams']['away']['id'] ?? null, FILTER_VALIDATE_INT);
            $timestamp = filter_var($data['fixture']['timestamp'] ?? null, FILTER_VALIDATE_INT);
            $season = filter_var($data['league']['season'] ?? null, FILTER_VALIDATE_INT);
            $incomingStatus = FootballFixtureStatus::normalize($data['fixture']['status']['short'] ?? null);

            if (! $fixtureId || ! $leagueApiId || ! $homeApiId || ! $awayApiId || ! $timestamp || ! $season || $incomingStatus === null) {
                $result['skipped']++;

                continue;
            }

            $league = League::where('api_league_id', $leagueApiId)->first();
            if (! $league) {
                $result['skipped']++;

                continue;
            }

            $outcome = DB::transaction(function () use ($data, $fixtureId, $league, $homeApiId, $awayApiId, $timestamp, $season, $incomingStatus): string {
                $existing = Fixture::where('api_fixture_id', $fixtureId)->lockForUpdate()->first();
                if ($existing && in_array(FootballFixtureStatus::normalize($existing->status_short), self::PROTECTED_EXISTING_STATUSES, true)) {
                    return 'protected';
                }

                $homeTeam = Team::updateOrCreate(
                    ['api_team_id' => $homeApiId],
                    ['name' => $data['teams']['home']['name'], 'logo_url' => $data['teams']['home']['logo'] ?? null],
                );
                $awayTeam = Team::updateOrCreate(
                    ['api_team_id' => $awayApiId],
                    ['name' => $data['teams']['away']['name'], 'logo_url' => $data['teams']['away']['logo'] ?? null],
                );

                $fixture = Fixture::updateOrCreate(
                    ['api_fixture_id' => $fixtureId],
                    [
                        'league_id' => $league->id,
                        'home_team_id' => $homeTeam->id,
                        'away_team_id' => $awayTeam->id,
                        'season' => $season,
                        'round' => $data['league']['round'] ?? null,
                        'kick_off' => CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d H:i:s'),
                        'status_long' => $data['fixture']['status']['long'] ?? null,
                        'status_short' => $incomingStatus,
                        'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
                        'venue_name' => $data['fixture']['venue']['name'] ?? null,
                        'referee' => $data['fixture']['referee'] ?? null,
                    ],
                );

                FixtureScore::updateOrCreate(
                    ['fixture_id' => $fixture->id],
                    [
                        'goals_home' => $data['goals']['home'] ?? null,
                        'goals_away' => $data['goals']['away'] ?? null,
                        'home_fulltime' => $data['score']['fulltime']['home'] ?? null,
                        'away_fulltime' => $data['score']['fulltime']['away'] ?? null,
                        'home_halftime' => $data['score']['halftime']['home'] ?? null,
                        'away_halftime' => $data['score']['halftime']['away'] ?? null,
                        'home_extratime' => $data['score']['extratime']['home'] ?? null,
                        'away_extratime' => $data['score']['extratime']['away'] ?? null,
                        'home_penalties' => $data['score']['penalty']['home'] ?? null,
                        'away_penalties' => $data['score']['penalty']['away'] ?? null,
                    ],
                );

                return 'upserted';
            });

            $result[$outcome]++;
        }

        return $result;
    }
}
