<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CareMessage extends Notification
{
    /** @param array<string, mixed> $payload */
    public function __construct(public string $kind, public array $payload) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $result = $this->kind === 'result';
        $message = (new MailMessage)->subject($result ? 'Verbindliches Ergebnis der Notbetreuung' : 'Rückmeldung zur Notbetreuung')
            ->greeting('Liebe Familie,')->line('Notbetreuung am '.$this->payload['date'].' für '.$this->payload['child_name'].'.');
        $message->line($result ? ($this->payload['awarded'] ? 'Der Elternbeirat hat einen Betreuungsplatz verbindlich bestätigt.' : 'In diesem Verfahren wurde kein Betreuungsplatz vergeben.') : 'Bitte teilen Sie uns Ihren Betreuungsbedarf mit. Ohne Rückmeldung bleibt ein regulärer Anspruch bestehen.');
        if (isset($this->payload['care_time'])) {
            $message->line($this->payload['care_time'].'.');
        }
        if (! $result && $this->payload['deadline']) {
            $message->line('Rückmeldefrist: '.$this->payload['deadline'].' (Europe/Berlin).');
        }
        if ($result && $this->payload['awarded']) {
            $message->line('Den gültigen Betreuungszeitraum finden Sie über Ihren persönlichen Link.');
        }

        return $message->action($result ? 'Ergebnis ansehen' : 'Rückmeldung abgeben', route('parent.show', $this->payload['token']))->salutation('Ihr Elternbeirat');
    }
}
