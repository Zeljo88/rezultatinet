<?php
namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\Post;
use Illuminate\Console\Command;

class GenerateMatchReport extends Command
{
    protected $signature = 'generate:match-report {fixture_id? : Fixture ID (optional, auto-detects recent FT matches)}';
    protected $description = 'Auto-generate a post-match blog post from DB data';

    public function handle(): void
    {
        $fixtureId = $this->argument('fixture_id');

        if ($fixtureId) {
            $fixture = Fixture::with(['homeTeam','awayTeam','score','league','events'])->find($fixtureId);
        } else {
            // Auto-detect: find most recent FT match from a top league
            $topLeagues = [2, 3, 848, 39, 140, 135, 78, 61, 210, 286, 315];
            $fixture = Fixture::with(['homeTeam','awayTeam','score','league','events'])
                ->whereIn('status_short', ['FT','AET','PEN'])
                ->whereHas('league', fn($q) => $q->whereIn('api_league_id', $topLeagues))
                ->whereDate('kick_off', today())
                ->orderByDesc('kick_off')
                ->first();
        }

        if (!$fixture) {
            $this->error('No fixture found.');
            return;
        }

        $home = $fixture->homeTeam->name;
        $away = $fixture->awayTeam->name;
        $scoreHome = $fixture->score?->home_fulltime ?? $fixture->score?->goals_home ?? 0;
        $scoreAway = $fixture->score?->away_fulltime ?? $fixture->score?->goals_away ?? 0;
        $league = $fixture->league->name;
        $date = \Carbon\Carbon::parse($fixture->kick_off)->format('d.m.Y');
        $slug = \Illuminate\Support\Str::slug("{$home}-{$away}-rezultat");

        // Determine winner
        $resultText = match(true) {
            $scoreHome > $scoreAway => "{$home} pobijedio",
            $scoreAway > $scoreHome => "{$away} pobijedio",
            default => 'Nerješeno'
        };

        // Build events summary
        $goals = $fixture->events->where('type', 'Goal')->where('detail', '!=', 'Own Goal')->sortBy('elapsed_minute');
        $ownGoals = $fixture->events->where('type', 'Goal')->where('detail', 'Own Goal')->sortBy('elapsed_minute');
        $redCards = $fixture->events->where('type', 'Card')->where('detail', 'Red Card')->sortBy('elapsed_minute');
        $yellowCards = $fixture->events->where('type', 'Card')->where('detail', 'Yellow Card');

        $goalLines = '';
        foreach ($goals as $g) {
            $teamName = $g->team_id === $fixture->home_team_id ? $home : $away;
            $goalLines .= "- {$g->elapsed_minute}' {$g->player_name} ({$teamName})\n";
        }
        foreach ($ownGoals as $g) {
            $teamName = $g->team_id === $fixture->home_team_id ? $home : $away;
            $goalLines .= "- {$g->elapsed_minute}' {$g->player_name} (autogol, {$teamName})\n";
        }

        $redCardLines = '';
        foreach ($redCards as $r) {
            $teamName = $r->team_id === $fixture->home_team_id ? $home : $away;
            $redCardLines .= "- {$r->elapsed_minute}' {$r->player_name} ({$teamName})\n";
        }

        $htHome = $fixture->score?->home_halftime ?? '?';
        $htAway = $fixture->score?->away_halftime ?? '?';

        // Generate article content
        $content = "{$league} | {$date}

{$home} i {$away} odigrali su večerašnji okršaj u {$league}. Utakmica je završila rezultatom {$scoreHome}:{$scoreAway} — {$resultText}.

Tok utakmice

Utakmica je počela u korist " . ($scoreHome >= $scoreAway ? $home : $away) . ", a poluvrijeme je donijelo rezultat {$htHome}:{$htAway}. " .
($goals->count() > 0 ? "Ukupno je postignut" . ($goals->count() === 1 ? '' : 'o') . " {$goals->count()} " . ($goals->count() === 1 ? 'pogodak' : ($goals->count() < 5 ? 'pogotka' : 'pogodaka')) . " u ovoj utakmici." : "Utakmica je završila bez golova.") . "

Golovi
" . ($goalLines ?: "Nije bilo golova.\n") . "
" . ($redCardLines ? "Crveni kartoni\n{$redCardLines}\n" : '') . "
Žuti kartoni: {$yellowCards->count()}

Pratite {$home} i {$away} rezultate

Sve detalje ove i ostalih utakmica pratite u realnom vremenu na rezultati.net — vaša prva adresa za live rezultate u regiji.";

        $keyword = "{$home} {$away} rezultat";
        $metaTitle = mb_substr("{$home} {$away} {$scoreHome}:{$scoreAway} | rezultati.net", 0, 60);
        $metaDesc = mb_substr("{$home} {$away} završnica: {$scoreHome}:{$scoreAway}. Izvještaj, golovi i statistike s utakmice {$league} na rezultati.net.", 0, 155);

        $post = Post::updateOrCreate(
            ['slug' => $slug],
            [
                'title' => "{$home} – {$away} {$scoreHome}:{$scoreAway} | Izvještaj s utakmice",
                'keyword' => $keyword,
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
                'content' => $content,
                'published' => true,
            ]
        );

        $this->info("✅ Published: /blog/{$slug}");
        $this->info("Title: {$post->title}");
        $this->info("Fixture: {$home} {$scoreHome}:{$scoreAway} {$away} ({$league})");
        $this->info("Goals: " . $goals->count() . " | Red cards: " . $redCards->count());
    }
}
