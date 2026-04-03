<?php

namespace App\Notifications;

use App\Services\OneSignalService;

class GoalNotification
{
    public function __construct(
        public string $homeTeam,
        public string $awayTeam,
        public int $goalsHome,
        public int $goalsAway,
        public string $league,
        public int $fixtureId,
        public ?string $scorerName = null,
        public ?int $minute = null
    ) {}

    public function send(): bool
    {
        $score     = "{$this->goalsHome}:{$this->goalsAway}";
        $minuteStr = $this->minute ? " {$this->minute}'" : '';
        $scorer    = $this->scorerName ? "{$this->scorerName}{$minuteStr} — " : '';

        return app(OneSignalService::class)->sendToAll(
            title:   '⚽ GOL!',
            message: "{$scorer}{$this->homeTeam} {$score} {$this->awayTeam}",
            url:     "https://rezultati.net/utakmica/{$this->fixtureId}",
            data:    ['fixture_id' => $this->fixtureId],
        );
    }
}
