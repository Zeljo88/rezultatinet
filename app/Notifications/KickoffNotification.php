<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\OneSignal\OneSignalChannel;
use NotificationChannels\OneSignal\OneSignalMessage;

class KickoffNotification extends Notification
{
    public function __construct(
        public string $homeTeam,
        public string $awayTeam,
        public string $league,
        public int $fixtureId
    ) {}

    public function via($notifiable): array
    {
        return [OneSignalChannel::class];
    }

    public function toOneSignal($notifiable): OneSignalMessage
    {
        return OneSignalMessage::create()
            ->setSubject("⚽ Počelo!")
            ->setBody("{$this->homeTeam} vs {$this->awayTeam} — {$this->league}")
            ->setParameter('url', "https://rezultati.net/utakmica/{$this->fixtureId}")
            ->setData('fixture_id', $this->fixtureId);
    }
}
