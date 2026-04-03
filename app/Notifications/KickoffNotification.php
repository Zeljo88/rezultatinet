<?php

namespace App\Notifications;

use App\Services\OneSignalService;

class KickoffNotification
{
    public function __construct(
        public string $homeTeam,
        public string $awayTeam,
        public string $league,
        public int $fixtureId
    ) {}

    public function send(): bool
    {
        return app(OneSignalService::class)->sendToAll(
            title:   '⚽ Počelo!',
            message: "{$this->homeTeam} vs {$this->awayTeam} — {$this->league}",
            url:     "https://rezultati.net/utakmica/{$this->fixtureId}",
            data:    ['fixture_id' => $this->fixtureId],
        );
    }
}
