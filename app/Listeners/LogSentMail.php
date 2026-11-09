<?php

namespace App\Listeners;

use App\Models\MailLog;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\TextPart;

class LogSentMail
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $html = $message->getHtmlBody();

        MailLog::create([
            'from' => $this->addresses($message->getFrom()),
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'bcc' => $this->addresses($message->getBcc()),
            'subject' => $message->getSubject() ?? '',
            'html' => $html !== null
                ? (new TextPart($html))->getBody()
                : '<pre>'.e((new TextPart($message->getTextBody() ?? ''))->getBody()).'</pre>',
            'message_id' => $event->sent->getMessageId(),
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  Address[]  $addresses
     * @return list<array{address: string, name: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): array => [
            'address' => $address->getAddress(),
            'name' => $address->getName(),
        ], $addresses));
    }
}
