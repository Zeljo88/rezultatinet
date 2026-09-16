<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\Team;
use App\Support\MatchRoute;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MatchRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge();

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('api_team_id')->unique();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('short_name')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
        });
        Schema::create('fixtures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('api_fixture_id')->unique();
            $table->unsignedBigInteger('home_team_id');
            $table->unsignedBigInteger('away_team_id');
            $table->dateTime('kick_off');
            $table->string('status_short')->nullable();
            $table->timestamps();
        });
    }

    public function test_sitemap_filter_keeps_only_urls_resolvable_to_their_fixture_data(): void
    {
        $shadowHome = $this->team(1, 'Bolivar', 'bolivar');
        $shadowAway = $this->team(2, 'Aurora', 'aurora');
        $actualHome = $this->team(3, 'Bolivar', 'bolivar');
        $actualAway = $this->team(4, 'Aurora', 'aurora');
        $unmatchedHome = $this->team(5, 'H&H Export', null);
        $unmatchedAway = $this->team(6, 'Managua', null);
        $uniqueHome = $this->team(7, 'Dinamo Zagreb', 'dinamo-zagreb');
        $uniqueAway = $this->team(8, 'Hajduk Split', 'hajduk-split');

        $unroutable = $this->fixture(10, $actualHome, $actualAway, '2026-09-14 18:00:00');
        $unmatched = $this->fixture(11, $unmatchedHome, $unmatchedAway, '2026-09-14 19:00:00');
        $routable = $this->fixture(12, $uniqueHome, $uniqueAway, '2026-09-14 20:00:00');

        $fixtures = Fixture::with(['homeTeam', 'awayTeam'])->orderBy('id')->get();
        $filtered = MatchRoute::filterRoutableSitemapFixtures($fixtures);

        $this->assertSame([$routable->id], $filtered->pluck('id')->all());
        $this->assertNotSame($shadowHome->id, $actualHome->id);
        $this->assertNotSame($shadowAway->id, $actualAway->id);
    }

    private function team(int $apiId, string $name, ?string $slug): Team
    {
        return Team::query()->create([
            'api_team_id' => $apiId,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function fixture(int $apiId, Team $home, Team $away, string $kickOff): Fixture
    {
        return Fixture::query()->create([
            'api_fixture_id' => $apiId,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'kick_off' => $kickOff,
            'status_short' => 'NS',
        ]);
    }
}
