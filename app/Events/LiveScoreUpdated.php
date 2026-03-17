<?php
namespace App\Events;

use App\Models\Fixture;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveScoreUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Fixture $fixture) {}

    public function broadcastOn(): array {
        return [
            new Channel('live-scores'),
            new Channel("league.{$this->fixture->league_id}"),
            new Channel("fixture.{$this->fixture->id}"),
        ];
    }

    public function broadcastAs(): string { return 'score.updated'; }

    public function broadcastWith(): array {
        return [
            'fixture_id' => $this->fixture->id,
            'status'     => $this->fixture->status_short,
            'minute'     => $this->fixture->elapsed_minute,
            'home_goals' => $this->fixture->score?->home_fulltime ?? 0,
            'away_goals' => $this->fixture->score?->away_fulltime ?? 0,
            'home_team'  => $this->fixture->homeTeam?->name,
            'away_team'  => $this->fixture->awayTeam?->name,
        ];
    }
}
