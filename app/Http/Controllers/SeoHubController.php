<?php

namespace App\Http\Controllers;

use App\Models\BasketballGame;
use App\Support\SeoHubData;
use Carbon\Carbon;
use Illuminate\View\View;

class SeoHubController extends Controller
{
    public function sport(string $sport): View
    {
        abort_unless(in_array($sport, ['football', 'basketball', 'tennis'], true), 404);

        $windows = match ($sport) {
            'football' => SeoHubData::footballWindows(),
            'basketball' => SeoHubData::basketballWindows(),
            'tennis' => SeoHubData::tennisWindows(),
        };

        return view('home', [
            'sport' => $sport,
            'initialTab' => 'live',
            'hub' => $sport,
            'seoWindows' => $windows,
        ]);
    }

    public function today(): View
    {
        return view('seo.today', [
            'dateLabel' => $this->dateLabel(today()),
            'football' => SeoHubData::footballWindows(),
            'basketball' => SeoHubData::basketballWindows(),
            'tennis' => SeoHubData::tennisWindows(),
        ]);
    }

    public function basketballLeague(string $slug): View
    {
        $config = [
            'evroliga' => ['name' => 'Evroliga', 'data_name' => 'Euroleague'],
            'aba-liga' => ['name' => 'ABA Liga', 'data_name' => 'ABA League'],
        ][$slug] ?? null;
        abort_unless($config, 404);
        abort_unless(BasketballGame::where('league_name', $config['data_name'])->exists(), 404);

        return view('seo.basketball-league', [
            'slug' => $slug,
            'name' => $config['name'],
            'seasonLabel' => 'Aktuelna sezona',
            'windows' => SeoHubData::basketballWindows($config['data_name']),
        ]);
    }

    private function dateLabel(Carbon $date): string
    {
        return $date->locale('bs')->translatedFormat('j. F Y.');
    }
}
