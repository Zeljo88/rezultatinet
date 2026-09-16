<?php

namespace App\Jobs;

use App\Events\LiveScoreUpdated;
use App\Exceptions\ApiFootballBlocked;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureScore;
use App\Models\League;
use App\Models\Team;
use App\Notifications\GoalNotification;
use App\Notifications\KickoffNotification;
use App\Services\ApiFootballService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class FetchLiveFixtures implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 90;

    public function uniqueId(): string
    {
        return 'api-football-live-poll';
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('api-football-live-poll'))->dontRelease()->expireAfter(60)];
    }

    public function handle(ApiFootballService $api): void
    {
        // Night guard — between 01:00 and 07:00 UTC, skip polling if no live matches in DB
        // This prevents burning ~720 API calls nightly when there are zero matches
        $hour = (int) now()->format('G');
        if ($hour >= 1 && $hour < 7) {
            $hasLive = Fixture::whereIn('status_short', ['1H', 'HT', '2H', 'ET', 'P', 'BT'])
                ->where('kick_off', '>=', now()->subHours(4))
                ->where('updated_at', '>=', now()->subMinutes(20))
                ->exists();
            if (! $hasLive) {
                $this->limitedLog('night-skip', 'info', 'live_poll_night_skip');

                return;
            }
        }

        try {
            $fixtures = $api->getLiveFixtures();
        } catch (ApiFootballBlocked) {
            return;
        }

        foreach ($fixtures as $data) {
            $league = League::where('api_league_id', $data['league']['id'])->first();
            if (! $league) {
                $this->limitedLog('unknown-league:'.$data['league']['id'], 'warning', 'live_fixture_unknown_league', [
                    'league_api_id' => $data['league']['id'], 'fixture_api_id' => $data['fixture']['id'],
                ]);

                continue;
            }

            $homeTeam = Team::where('api_team_id', $data['teams']['home']['id'])->first();
            $awayTeam = Team::where('api_team_id', $data['teams']['away']['id'])->first();

            // Guard: skip fixture if team data is missing
            if (! $homeTeam || ! $awayTeam) {
                $this->limitedLog('missing-team:'.$data['fixture']['id'], 'warning', 'live_fixture_missing_team', [
                    'fixture_api_id' => $data['fixture']['id'], 'league_id' => $league->id,
                    'home_api_id' => $data['teams']['home']['id'] ?? null, 'away_api_id' => $data['teams']['away']['id'] ?? null,
                    'home_found' => (bool) $homeTeam, 'away_found' => (bool) $awayTeam,
                ]);

                continue;
            }

            // Capture previous state for push notification diffs
            $existingFixture = Fixture::where('api_fixture_id', $data['fixture']['id'])->first();
            $oldStatus = $existingFixture?->status_short;
            $oldScore = $existingFixture?->score;

            $fixture = Fixture::updateOrCreate(
                ['api_fixture_id' => $data['fixture']['id']],
                [
                    'status_long' => $data['fixture']['status']['long'] ?? null,
                    'status_short' => $data['fixture']['status']['short'] ?? null,
                    'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
                    'elapsed_extra' => $data['fixture']['status']['extra'] ?: null,
                    'season' => $data['league']['season'],
                    'league_id' => $league->id,
                    'home_team_id' => $homeTeam->id,
                    'away_team_id' => $awayTeam->id,
                    'kick_off' => $data['fixture']['date'] ?? null,
                ]
            );

            FixtureScore::updateOrCreate(
                ['fixture_id' => $fixture->id],
                [
                    'goals_home' => $data['goals']['home'],
                    'goals_away' => $data['goals']['away'],
                    'home_halftime' => $data['score']['halftime']['home'] ?? null,
                    'away_halftime' => $data['score']['halftime']['away'] ?? null,
                    'home_fulltime' => $data['score']['fulltime']['home'] ?? null,
                    'away_fulltime' => $data['score']['fulltime']['away'] ?? null,
                    'home_extratime' => $data['score']['extratime']['home'] ?? null,
                    'away_extratime' => $data['score']['extratime']['away'] ?? null,
                    'home_penalties' => $data['score']['penalty']['home'] ?? null,
                    'away_penalties' => $data['score']['penalty']['away'] ?? null,
                ]
            );

            // ── OneSignal Push Triggers ─────────────────────────────────────────────
            $newStatus = $data['fixture']['status']['short'] ?? null;

            // Kickoff: NS -> 1H
            if ($oldStatus === 'NS' && $newStatus === '1H') {
                try {
                    (new KickoffNotification(
                        homeTeam: $homeTeam->name,
                        awayTeam: $awayTeam->name,
                        league: $league->name,
                        fixtureId: $fixture->id,
                    ))->send();
                } catch (\Throwable $e) {
                    Log::warning('KickoffNotification failed: '.$e->getMessage());
                }
            }

            // Goal: score change while live
            $liveStatuses = ['1H', 'HT', '2H', 'ET', 'P', 'BT'];
            if (in_array($newStatus, $liveStatuses)) {
                $newGoalsHome = $data['goals']['home'] ?? 0;
                $newGoalsAway = $data['goals']['away'] ?? 0;
                $oldGoalsHome = $oldScore?->goals_home ?? null;
                $oldGoalsAway = $oldScore?->goals_away ?? null;

                if ($oldGoalsHome !== null && ($newGoalsHome > $oldGoalsHome || $newGoalsAway > $oldGoalsAway)) {
                    $scorerName = null;
                    $scorerMinute = null;
                    if (! empty($data['events'])) {
                        foreach (array_reverse($data['events']) as $ev) {
                            if (in_array($ev['type'] ?? '', ['Goal', 'goal'])) {
                                $scorerName = $ev['player']['name'] ?? null;
                                $scorerMinute = $ev['time']['elapsed'] ?? null;
                                break;
                            }
                        }
                    }
                    try {
                        (new GoalNotification(
                            homeTeam: $homeTeam->name,
                            awayTeam: $awayTeam->name,
                            goalsHome: (int) $newGoalsHome,
                            goalsAway: (int) $newGoalsAway,
                            league: $league->name,
                            fixtureId: $fixture->id,
                            scorerName: $scorerName,
                            minute: $scorerMinute ? (int) $scorerMinute : null,
                        ))->send();
                    } catch (\Throwable $e) {
                        Log::warning('GoalNotification failed: '.$e->getMessage());
                    }
                }
            }
            // ────────────────────────────────────────────────────────────────────────

            if (! empty($data['events'])) {
                FixtureEvent::where('fixture_id', $fixture->id)->delete();
                foreach ($data['events'] as $event) {
                    $team = Team::where('api_team_id', $event['team']['id'] ?? 0)->first();
                    FixtureEvent::create([
                        'fixture_id' => $fixture->id,
                        'team_id' => $team?->id,
                        'player_name' => $event['player']['name'] ?? null,
                        'assist_name' => $event['assist']['name'] ?? null,
                        'type' => $event['type'] ?? 'Goal',
                        'detail' => $event['detail'] ?? null,
                        'elapsed_minute' => $event['time']['elapsed'] ?? null,
                        'elapsed_extra' => $event['time']['extra'] ?: null,
                    ]);
                }
            }

            // Dispatch lineup fetch only for top leagues + within 60 min of kickoff (or already live)
            $topLeagueIds = [2, 3, 848, 39, 140, 135, 78, 61, 210, 286, 315];
            $isTopLeague = in_array($league->api_league_id, $topLeagueIds);

            $isLive = in_array($fixture->status_short, ['1H', 'HT', '2H', 'ET', 'P', 'BT']);
            $kickoffSoon = $fixture->kick_off && now()->diffInMinutes($fixture->kick_off, false) <= 60;

            $needsLineups = ! $fixture->lineups_fetched_at || ! $fixture->lineups_fetched_at->isToday();

            if ($needsLineups && $fixture->api_fixture_id && $isTopLeague && ($isLive || $kickoffSoon)) {
                FetchFixtureLineups::dispatch($fixture->id, $fixture->api_fixture_id);
            }

            try {
                broadcast(new LiveScoreUpdated($fixture->fresh(['score', 'homeTeam', 'awayTeam'])));
            } catch (\Throwable $broadcastError) {
                Log::warning('Broadcast failed (scores already saved): '.$broadcastError->getMessage());
            }
        }
    }

    private function limitedLog(string $key, string $level, string $message, array $context = []): void
    {
        try {
            RateLimiter::attempt('api-football:job-log:'.sha1($key), 1, function () use ($level, $message, $context) {
                Log::channel('api_football')->log($level, $message, $context);
            }, 300);
        } catch (\Throwable) {
            // Telemetry must never make a provider job retry or write to the legacy giant log.
        }
    }
}
