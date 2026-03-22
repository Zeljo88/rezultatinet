<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Models\ApiCallLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncFixStuck extends Command
{
    protected $signature   = 'sync:fix-stuck {--dry-run : Samo prikaži koje bi utakmice bile ažurirane}';
    protected $description = 'Ispravlja utakmice zaglavljene u live statusu (1H/2H/HT/ET/P) duže od 2 sata';

    // Statusi koji se smatraju "završenim" — postavljamo FT ako API kaže završeno
    protected const STUCK_STATUSES = ['1H', '2H', 'HT', 'ET', 'P'];

    // API limit buffer — ne idemo ispod ovoga
    protected const API_BUDGET = 7400;

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $this->info('[sync:fix-stuck] Tražim zaglavljene utakmice...');

        // Pronađi sve zaglavljene fixture starije od 2 sata
        $stuck = Fixture::whereIn('status_short', self::STUCK_STATUSES)
            ->where('updated_at', '<', now()->subHours(2))
            ->get(['id', 'api_fixture_id', 'status_short', 'elapsed_minute']);

        if ($stuck->isEmpty()) {
            $this->info('Nema zaglavljenih utakmica. ✓');
            return Command::SUCCESS;
        }

        $this->info("Pronađeno {$stuck->count()} zaglavljenih utakmica.");

        if ($isDryRun) {
            $this->table(
                ['local_id', 'api_fixture_id', 'status_short', 'elapsed_minute'],
                $stuck->map(fn($f) => [$f->id, $f->api_fixture_id, $f->status_short, $f->elapsed_minute])->toArray()
            );
            $this->warn('--dry-run: Nije napravljeno nijedno ažuriranje.');
            return Command::SUCCESS;
        }

        // Provjeri API budget
        $todayCount = ApiCallLog::getTodayCount();
        $available  = self::API_BUDGET - $todayCount;

        $this->info("API pozivi danas: {$todayCount}/" . self::API_BUDGET . " (dostupno: {$available})");

        if ($available <= 0) {
            $this->error('API budget iscrpljen. Preskaćem.');
            Log::warning('[sync:fix-stuck] API budget iscrpljen, preskočeno ' . $stuck->count() . ' utakmica.');
            return Command::FAILURE;
        }

        // Procesiramo samo onoliko koliko imamo budžeta
        $toProcess = $stuck->take($available);
        if ($toProcess->count() < $stuck->count()) {
            $this->warn("Budžet dopušta samo {$toProcess->count()} od {$stuck->count()} utakmica.");
        }

        $updated   = 0;
        $unchanged = 0;
        $failed    = 0;

        foreach ($toProcess as $fixture) {
            $apiId = $fixture->api_fixture_id;
            $this->line("  → Fixture #{$fixture->id} (api_id={$apiId}, trenutno: {$fixture->status_short})...");

            try {
                $response = Http::withHeaders([
                    'x-apisports-key' => config('services.api_football.key'),
                ])->timeout(15)->get('https://v3.football.api-sports.io/fixtures', ['id' => $apiId]);

                // Logiraj API poziv
                ApiCallLog::create([
                    'endpoint'    => "/fixtures?id={$apiId}",
                    'called_date' => today(),
                ]);

                if (!$response->successful()) {
                    $this->error("    HTTP {$response->status()} — preskačem.");
                    $failed++;
                    continue;
                }

                $data = $response->json('response.0');

                if (!$data) {
                    $this->warn("    Prazna API response — preskačem.");
                    $failed++;
                    continue;
                }

                $newStatus   = $data['fixture']['status']['short']   ?? null;
                $newLong     = $data['fixture']['status']['long']    ?? null;
                $newElapsed  = $data['fixture']['status']['elapsed'] ?? null;
                $goalsHome   = $data['goals']['home']  ?? null;
                $goalsAway   = $data['goals']['away']  ?? null;
                $score       = $data['score']          ?? [];

                $oldStatus = $fixture->status_short;

                // Ažuriraj fixture
                $fixture->update([
                    'status_short'   => $newStatus,
                    'status_long'    => $newLong,
                    'elapsed_minute' => $newElapsed,
                ]);

                // Ažuriraj scores (upsert po fixture_id)
                FixtureScore::where('fixture_id', $fixture->id)->update([
                    'goals_home'     => $goalsHome,
                    'goals_away'     => $goalsAway,
                    'home_halftime'  => $score['halftime']['home']  ?? null,
                    'away_halftime'  => $score['halftime']['away']  ?? null,
                    'home_fulltime'  => $score['fulltime']['home']  ?? null,
                    'away_fulltime'  => $score['fulltime']['away']  ?? null,
                    'home_extratime' => $score['extratime']['home'] ?? null,
                    'away_extratime' => $score['extratime']['away'] ?? null,
                    'home_penalties' => $score['penalty']['home']   ?? null,
                    'away_penalties' => $score['penalty']['away']   ?? null,
                    'updated_at'     => now(),
                ]);

                $scoreStr = "{$goalsHome}-{$goalsAway}";
                $this->info("    {$oldStatus} → {$newStatus} | score: {$scoreStr} ✓");

                Log::info("[sync:fix-stuck] Fixture #{$fixture->id} (api_id={$apiId}): {$oldStatus} → {$newStatus} | {$scoreStr}");

                $updated++;

            } catch (\Exception $e) {
                $this->error("    Greška: " . $e->getMessage());
                Log::error("[sync:fix-stuck] Fixture #{$fixture->id}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("========================================");
        $this->info("Ažurirano:  {$updated}");
        $this->info("Nepromijenjeno: {$unchanged}");
        $this->warn("Greške:     {$failed}");
        $this->info("========================================");

        Log::info("[sync:fix-stuck] Završeno: updated={$updated}, failed={$failed}");

        return Command::SUCCESS;
    }
}
