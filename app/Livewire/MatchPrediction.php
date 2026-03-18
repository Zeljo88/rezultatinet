<?php

namespace App\Livewire;

use App\Models\Fixture;
use App\Models\Prediction;
use Illuminate\Support\Facades\Request;
use Livewire\Component;

class MatchPrediction extends Component
{
    public int $fixtureId;
    public string $homeTeam;
    public string $awayTeam;
    public bool $hasVoted = false;
    public ?string $userVote = null;

    public function mount(int $fixtureId, string $homeTeam, string $awayTeam): void
    {
        $this->fixtureId = $fixtureId;
        $this->homeTeam  = $homeTeam;
        $this->awayTeam  = $awayTeam;

        $sessionKey = 'voted_' . $fixtureId;
        if (session()->has($sessionKey)) {
            $this->hasVoted = true;
            $this->userVote = session($sessionKey);
        }
    }

    public function vote(string $choice): void
    {
        if ($this->hasVoted) return;

        $allowed = ['home', 'draw', 'away'];
        if (!in_array($choice, $allowed, true)) return;

        // Block voting on finished matches
        $fixture = Fixture::find($this->fixtureId);
        if ($fixture && in_array($fixture->status_short, ['FT', 'AET', 'PEN', 'AWD', 'WO'])) return;

        $sessionKey = 'voted_' . $this->fixtureId;
        $ip         = Request::ip();
        $sessionId  = session()->getId();

        $alreadyVoted = Prediction::where('fixture_id', $this->fixtureId)
            ->where(function ($q) use ($ip, $sessionId) {
                $q->where('ip', $ip)->orWhere('session_id', $sessionId);
            })
            ->exists();

        if ($alreadyVoted) {
            $this->hasVoted = true;
            $this->userVote = $choice;
            session([$sessionKey => $choice]);
            return;
        }

        Prediction::create([
            'fixture_id' => $this->fixtureId,
            'vote'       => $choice,
            'ip'         => $ip,
            'session_id' => $sessionId,
        ]);

        $this->hasVoted = true;
        $this->userVote = $choice;
        session([$sessionKey => $choice]);
    }

    public function getStatsProperty(): array
    {
        return Prediction::getStats($this->fixtureId);
    }

    /**
     * Returns 'home', 'draw', or 'away' based on final score.
     * Returns null if match not finished or no score.
     */
    public function getActualResultProperty(): ?string
    {
        $fixture = Fixture::with('score')->find($this->fixtureId);
        if (!in_array($fixture?->status_short, ['FT', 'AET', 'PEN', 'AWD', 'WO'])) {
            return null;
        }

        $score = $fixture->score;
        if (!$score) return null;

        // For AET/PEN use extra time / penalties if available, else fulltime
        $home = $score->home_penalties ?? $score->home_extratime ?? $score->home_fulltime;
        $away = $score->away_penalties ?? $score->away_extratime ?? $score->away_fulltime;

        if (is_null($home) || is_null($away)) return null;

        if ($home > $away) return 'home';
        if ($away > $home) return 'away';
        return 'draw';
    }

    public function render()
    {
        return view('livewire.match-prediction', [
            'stats'        => $this->stats,
            'actualResult' => $this->actualResult,
        ]);
    }
}
