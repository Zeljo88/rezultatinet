<?php

namespace App\Services;

use App\Models\League;
use App\Models\Standing;
use App\Models\Team;
use App\Services\ApiFootball\ApiFootballGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Reviewed 2026/27 scope only. A rollover/format/roster change needs review. */
final class HnlStandingsSync
{
    public const LEAGUE = 835;

    public const PROVIDER_LEAGUE = 210;

    public const SEASON = 2026;

    // Provider => local identities from season-2026 fixtures (including rounds 9/10),
    // corroborated by the complete 210/2026 standings preview, NOT archived rows.
    public const TEAMS = [561 => 1447, 608 => 1507, 616 => 1511, 620 => 1506,
        1016 => 1510, 1017 => 1512, 1018 => 1509, 1019 => 2743, 1068 => 1513, 1483 => 1508];

    public function __construct(private readonly ApiFootballGateway $gateway) {}

    /** Strict, HNL-only supported single-group provider contract. Never repair malformed data. */
    public function validate(mixed $envelope): array
    {
        $this->require(is_array($envelope) && ! array_is_list($envelope), 'envelope');
        $this->require(($envelope['get'] ?? null) === 'standings', 'endpoint');
        $parameters = $envelope['parameters'] ?? null;
        $this->require(is_array($parameters) && count($parameters) === 2
            && in_array($parameters['league'] ?? null, [210, '210'], true)
            && in_array($parameters['season'] ?? null, [2026, '2026'], true), 'parameters');
        $this->require(($envelope['errors'] ?? null) === [] && ($envelope['results'] ?? null) === 1
            && ($envelope['paging']['current'] ?? null) === 1
            && ($envelope['paging']['total'] ?? null) === 1, 'success_complete_page');
        $response = $envelope['response'] ?? null;
        $this->require(is_array($response) && array_is_list($response) && count($response) === 1, 'response');
        $league = $response[0]['league'] ?? null;
        $this->require(is_array($league) && ($league['id'] ?? null) === 210
            && ($league['season'] ?? null) === 2026, 'league_season');
        $groups = $league['standings'] ?? null;
        $this->require(is_array($groups) && array_is_list($groups) && count($groups) === 1
            && is_array($groups[0]) && array_is_list($groups[0]), 'single_group');
        $rows = $groups[0];
        // Completeness = exact independently bound seasonal roster, not results=team count
        // (API results counts leagues) and not an arbitrary historical row-count threshold.
        $this->require(count($rows) === count(self::TEAMS), 'roster_size');
        $ids = [];
        $totals = ['win' => 0, 'lose' => 0, 'draw' => 0, 'for' => 0, 'against' => 0];
        $venueTotals = array_fill_keys(['home', 'away'], array_fill_keys(['played', 'win', 'lose', 'draw', 'for', 'against'], 0));
        $previousPoints = PHP_INT_MAX;
        $mapped = [];
        foreach ($rows as $index => $row) {
            $this->require(is_array($row) && ($row['rank'] ?? null) === $index + 1
                && ($row['group'] ?? null) === 'HNL', 'ordered_rank_group');
            $id = $row['team']['id'] ?? null;
            $this->require(is_int($id) && isset(self::TEAMS[$id]) && ! in_array($id, $ids, true), 'team_identity');
            $ids[] = $id;
            $this->require(is_string($row['team']['name'] ?? null)
                && trim($row['team']['name']) !== '' && mb_strlen($row['team']['name']) <= 100, 'team_name');
            foreach (['all', 'home', 'away'] as $scope) {
                foreach (['played', 'win', 'draw', 'lose'] as $field) {
                    $this->integer($row[$scope][$field] ?? null, 0, $scope === 'all' ? 65535 : 255);
                }
                foreach (['for', 'against'] as $field) {
                    $this->integer($row[$scope]['goals'][$field] ?? null, 0, 65535);
                }
                $part = $row[$scope];
                $this->require($part['played'] === $part['win'] + $part['draw'] + $part['lose'], 'played_sum');
            }
            foreach (['played', 'win', 'draw', 'lose'] as $field) {
                $this->require($row['all'][$field] === $row['home'][$field] + $row['away'][$field], 'home_away_sum');
            }
            foreach (['for', 'against'] as $field) {
                $this->require($row['all']['goals'][$field] === $row['home']['goals'][$field] + $row['away']['goals'][$field], 'goals_sum');
            }
            $this->integer($row['points'] ?? null, 0, 65535);
            $this->integer($row['goalsDiff'] ?? null, -32768, 32767);
            // A sanctioned points adjustment is not silently guessed; review its evidence first.
            $this->require($row['points'] === 3 * $row['all']['win'] + $row['all']['draw']
                && $row['points'] <= $previousPoints, 'points');
            $this->require($row['goalsDiff'] === $row['all']['goals']['for'] - $row['all']['goals']['against'], 'goal_diff');
            $previousPoints = $row['points'];
            foreach (['form' => 10, 'description' => 255] as $field => $limit) {
                $this->require(array_key_exists($field, $row) && ($row[$field] === null
                    || (is_string($row[$field]) && mb_strlen($row[$field]) <= $limit)), 'optional_text');
            }
            $this->require($row['form'] === null || preg_match('/^[WDL]{0,5}$/D', $row['form']) === 1, 'form');
            $this->require(is_string($row['update'] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $row['update']) === 1
                && strtotime($row['update']) !== false && strtotime($row['update']) <= time() + 300, 'provider_updated_at');
            foreach (['win', 'lose', 'draw'] as $field) {
                $totals[$field] += $row['all'][$field];
            }
            foreach (['for', 'against'] as $field) {
                $totals[$field] += $row['all']['goals'][$field];
            }
            $value = ['rank' => $row['rank'], 'played' => $row['all']['played'],
                'win' => $row['all']['win'], 'draw' => $row['all']['draw'], 'lose' => $row['all']['lose'],
                'goals_for' => $row['all']['goals']['for'], 'goals_against' => $row['all']['goals']['against'],
                'goal_diff' => $row['goalsDiff'], 'points' => $row['points'],
                'form' => $row['form'], 'description' => $row['description']];
            foreach (['home', 'away'] as $scope) {
                foreach (['played', 'win', 'draw', 'lose'] as $field) {
                    $value[$scope.'_'.$field] = $row[$scope][$field];
                    $venueTotals[$scope][$field] += $row[$scope][$field];
                }
                foreach (['for', 'against'] as $field) {
                    $value[$scope.'_goals_'.$field] = $row[$scope]['goals'][$field];
                    $venueTotals[$scope][$field] += $row[$scope]['goals'][$field];
                }
            }
            $mapped[self::TEAMS[$id]] = $value;
        }
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        $this->require($sorted === array_keys(self::TEAMS), 'complete_roster');
        $this->require($totals['win'] === $totals['lose'] && $totals['draw'] % 2 === 0
            && $totals['for'] === $totals['against'], 'table_balance');

        // Every home appearance/result/goal has its matching away counterpart.
        foreach (['played' => 'played', 'win' => 'lose', 'lose' => 'win',
            'draw' => 'draw', 'for' => 'against', 'against' => 'for'] as $home => $away) {
            $this->require($venueTotals['home'][$home] === $venueTotals['away'][$away], 'venue_balance_'.$home);
        }

        return ['rows' => $mapped, 'ordered_provider_team_ids' => $ids,
            'payload_sha256' => hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR))];
    }

    public function sync(): array
    {
        $this->require(DB::transactionLevel() === 0, 'outer_transaction_not_supported');
        // Serialize BEFORE fetching: a slow older fetch cannot overtake a newer commit.
        // One DB attempt only: never replay a provider call after deadlock/rollback.
        $receipt = DB::transaction(function (): array {
            $league = League::whereKey(self::LEAGUE)->lockForUpdate()->first();
            $this->require($league && (int) $league->api_league_id === self::PROVIDER_LEAGUE
                && $league->sport === 'football' && $league->country === 'Croatia', 'local_league');
            $current = Standing::where('league_id', self::LEAGUE)->where('season', self::SEASON)
                ->orderBy('team_id')->lockForUpdate()->get();
            $this->require($current->every(fn ($row): bool => in_array((int) $row->team_id, self::TEAMS, true)), 'current_snapshot_identity');
            $teams = Team::whereIn('id', array_values(self::TEAMS))->orderBy('id')->lockForUpdate()->get();
            $this->require($teams->count() === count(self::TEAMS), 'missing_local_team');
            foreach ($teams as $team) {
                $this->require((self::TEAMS[(int) $team->api_team_id] ?? null) === (int) $team->id, 'local_team_identity');
            }
            $season = DB::table('fixtures')->where('league_id', self::LEAGUE)
                ->where('kick_off', '<=', now('UTC'))->where('kick_off', '>=', now('UTC')->subDays(45))->max('season');
            $this->require((int) $season === self::SEASON, 'recorded_season_guard');
            $validated = null;
            $attemptClaims = 0;
            $started = now('UTC')->toIso8601String();
            $this->gateway->get('/standings', ['league' => 210, 'season' => 2026], 'standings', 'HnlStandingsSync',
                static function () use (&$attemptClaims): bool {
                    return ++$attemptClaims <= 2;
                },
                function (mixed $envelope) use (&$validated): void {
                    $validated = $this->validate($envelope);
                });
            $this->require($validated !== null, 'no_validated_response');
            $changed = 0;
            foreach ($validated['rows'] as $teamId => $values) {
                $row = Standing::firstOrNew(['league_id' => self::LEAGUE, 'team_id' => $teamId, 'season' => self::SEASON]);
                $row->fill($values);
                if ($row->isDirty()) {
                    $row->save();
                    $changed++;
                }
            }

            // Exact roster already verified; there is no cross-season or cross-league delete.
            return ['schema' => 1, 'league_id' => self::LEAGUE, 'provider_league_id' => 210, 'season' => 2026,
                'ordered_provider_team_ids' => $validated['ordered_provider_team_ids'],
                'payload_sha256' => $validated['payload_sha256'], 'rows' => count($validated['rows']),
                'changed_rows' => $changed, 'attempt_claims' => $attemptClaims,
                'started_at' => $started, 'validated_at' => now('UTC')->toIso8601String()];
        }, 1);
        $receipt['commit_observed_at'] = now('UTC')->toIso8601String();
        $receipt['outcome'] = 'committed';
        try {
            Log::info('hnl_standings_committed', $receipt);
        } catch (Throwable) {
            // DB commit already happened. Never report rollback or refetch after a receipt failure.
            $receipt['outcome'] = 'committed_receipt_unavailable';
        }

        return $receipt;
    }

    private function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new RuntimeException('hnl_standings_'.$reason);
        }
    }

    private function integer(mixed $value, int $min, int $max): void
    {
        $this->require(is_int($value) && $value >= $min && $value <= $max, 'integer');
    }
}
