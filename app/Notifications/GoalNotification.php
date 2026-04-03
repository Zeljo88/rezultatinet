<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\OneSignal\OneSignalChannel;
use NotificationChannels\OneSignal\OneSignalMessage;

class GoalNotification extends Notification
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

    public function via($notifiable): array
    {
        return [OneSignalChannel::class];
    }

    public function toOneSignal($notifiable): OneSignalMessage
    {
        $score = "{$this->goalsHome}:{$this->goalsAway}";
        $minuteStr = $this->minute ? " {$this->minute}'" : '';
        $scorer = $this->scorerName ? "{$this->scorerName}{$minuteStr} — " : '';

        return OneSignalMessage::create()
            ->setSubject("⚽ GOL!")
            ->setBody("{$scorer}{$this->homeTeam} {$score} {$this->awayTeam}")
            ->setParameter('url', "https://rezultati.net/utakmica/{$this->fixtureId}")
            ->setData('fixture_id', $this->fixtureId);
    }
}
