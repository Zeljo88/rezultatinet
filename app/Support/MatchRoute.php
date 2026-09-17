<?php

namespace App\Support;

use App\Models\Fixture;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MatchRoute
{
    private const LEGACY_TEAM_LOOKUP_SQL = "LOWER(REPLACE(REPLACE(name, ' ', '-'), '.', ''))";

    public static function slugFor(Fixture $fixture): ?string
    {
        $homeSlug = self::teamSlug($fixture->homeTeam);
        $awaySlug = self::teamSlug($fixture->awayTeam);
        $date = $fixture->kick_off?->format('d-m-Y');

        if (!$homeSlug || !$awaySlug || !$date) {
            return null;
        }

        return "{$homeSlug}-vs-{$awaySlug}-{$date}";
    }

    public static function resolve(string $slug): ?Fixture
    {
        $parts = self::parse($slug);

        if (!$parts) {
            return null;
        }

        $homeTeam = self::resolveTeam($parts['home']);
        $awayTeam = self::resolveTeam($parts['away']);

        if (!$homeTeam || !$awayTeam) {
            return null;
        }

        return Fixture::with(['homeTeam', 'awayTeam', 'score', 'league', 'events'])
            ->where('home_team_id', $homeTeam->id)
            ->where('away_team_id', $awayTeam->id)
            ->whereDate('kick_off', $parts['date'])
            ->orderBy('id')
            ->first();
    }

    /**
     * Keep only sitemap fixtures whose generated slug resolves through the same
     * team lookup rules as the public match route.
     *
     * @param  Collection<int, Fixture>  $fixtures
     * @return Collection<int, Fixture>
     */
    public static function filterRoutableSitemapFixtures(Collection $fixtures): Collection
    {
        $entries = $fixtures->map(function (Fixture $fixture): array {
            $slug = self::slugFor($fixture);

            return [
                'fixture' => $fixture,
                'parts' => $slug ? self::parse($slug) : null,
            ];
        });

        $candidates = $entries
            ->flatMap(fn (array $entry) => $entry['parts']
                ? [$entry['parts']['home'], $entry['parts']['away']]
                : [])
            ->filter()
            ->unique()
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $resolvedTeamIds = collect();

        Team::query()
            ->whereIn('slug', $candidates)
            ->orderBy('id')
            ->get(['id', 'slug'])
            ->each(function (Team $team) use ($resolvedTeamIds): void {
                if (filled($team->slug) && !$resolvedTeamIds->has($team->slug)) {
                    $resolvedTeamIds->put($team->slug, $team->id);
                }
            });

        $fallbackCandidates = $candidates->diff($resolvedTeamIds->keys())->values();

        if ($fallbackCandidates->isNotEmpty()) {
            Team::query()
                ->whereIn(DB::raw(self::LEGACY_TEAM_LOOKUP_SQL), $fallbackCandidates)
                ->orderBy('id')
                ->get(['id', 'name'])
                ->each(function (Team $team) use ($fallbackCandidates, $resolvedTeamIds): void {
                    // The SQL comparison uses the database collation. Str::slug
                    // converts an accented matching value back to its URL key.
                    $candidate = Str::slug(str_replace('.', '', $team->name ?? ''));

                    if ($fallbackCandidates->contains($candidate) && !$resolvedTeamIds->has($candidate)) {
                        $resolvedTeamIds->put($candidate, $team->id);
                    }
                });
        }

        $fixtureKeys = $fixtures->mapWithKeys(fn (Fixture $fixture) => [
            self::fixtureKey(
                $fixture->home_team_id,
                $fixture->away_team_id,
                $fixture->kick_off?->format('Y-m-d'),
            ) => true,
        ]);

        return $entries
            ->filter(function (array $entry) use ($resolvedTeamIds, $fixtureKeys): bool {
                if (!$entry['parts']) {
                    return false;
                }

                $homeTeamId = $resolvedTeamIds->get($entry['parts']['home']);
                $awayTeamId = $resolvedTeamIds->get($entry['parts']['away']);

                if (!$homeTeamId || !$awayTeamId) {
                    return false;
                }

                return $fixtureKeys->has(self::fixtureKey(
                    $homeTeamId,
                    $awayTeamId,
                    $entry['parts']['date'],
                ));
            })
            ->pluck('fixture')
            ->values();
    }

    private static function resolveTeam(string $slug): ?Team
    {
        return Team::query()
            ->where('slug', $slug)
            ->orderBy('id')
            ->first()
            ?? Team::query()
                ->whereRaw(self::LEGACY_TEAM_LOOKUP_SQL . ' = ?', [$slug])
                ->orderBy('id')
                ->first();
    }

    /** @return array{home: string, away: string, date: string}|null */
    private static function parse(string $slug): ?array
    {
        if (!preg_match('/^(.+)-vs-(.+)-(\d{2})-(\d{2})-(\d{4})$/', $slug, $matches)) {
            return null;
        }

        $date = "{$matches[5]}-{$matches[4]}-{$matches[3]}";

        if (!checkdate((int) $matches[4], (int) $matches[3], (int) $matches[5])) {
            return null;
        }

        return [
            'home' => $matches[1],
            'away' => $matches[2],
            'date' => $date,
        ];
    }

    private static function teamSlug(?Team $team): ?string
    {
        if (!$team) {
            return null;
        }

        return filled($team->slug)
            ? $team->slug
            : (Str::slug($team->name ?? '') ?: null);
    }

    private static function fixtureKey(int|string|null $homeTeamId, int|string|null $awayTeamId, ?string $date): string
    {
        return "{$homeTeamId}|{$awayTeamId}|{$date}";
    }
}
