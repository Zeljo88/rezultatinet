<?php

use Illuminate\Support\Facades\DB;
use Tests\Feature\MatchTimeUiTest;

require_once __DIR__.'/../Feature/MatchTimeUiTest.php';

class RenderUiTest extends MatchTimeUiTest
{
    public function test_export_browser_fixtures(): void
    {
        $output = getenv('UI_BROWSER_OUTPUT');
        $this->assertNotFalse($output);
        $this->assertDirectoryExists($output);
        DB::table('fixtures')->insert([
            'api_fixture_id' => 1557417, 'league_id' => 1, 'home_team_id' => 1506,
            'away_team_id' => 1507, 'season' => 2026, 'kick_off' => '2026-10-10 11:30:00', 'status_short' => 'NS',
        ]);
        foreach (['home' => '/', 'yesterday' => '/jucer', 'tomorrow' => '/sutra'] as $name => $path) {
            $response = $this->get($path)->assertOk();
            file_put_contents($output.'/'.$name.'.html', $response->getContent());
        }

    }
}
