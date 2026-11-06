<?php

namespace App\Jobs;

use App\Models\EmailDelivery;
use App\Models\PersonalAccessToken;
use App\Notifications\CareMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendCareEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $tries = 4;

    public int $timeout = 45;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(): void
    {
        Cache::lock('care-email:'.$this->deliveryId, 60)->block(5, function (): void {
            $delivery = EmailDelivery::find($this->deliveryId);
            if (! $delivery || $delivery->sent_at) {
                return;
            }
            $payload = $delivery->payload;
            $access = PersonalAccessToken::where('token_hash', hash('sha256', $payload['token']))->first();
            if (! $access || $access->revoked_at || ($access->expires_at && $access->expires_at->lte(now()))) {
                $delivery->update(['attempts' => 4, 'last_error' => 'Persönlicher Link widerrufen oder abgelaufen. Versand abgebrochen.']);

                return;
            }
            $delivery->increment('attempts');
            try {
                $payload = $delivery->payload;
                Notification::route('mail', $payload['email'])->notify(new CareMessage($delivery->kind, $payload));
                $delivery->update(['sent_at' => now(), 'last_error' => null]);
            } catch (Throwable $exception) {
                $delivery->update(['last_error' => 'Versand fehlgeschlagen: '.class_basename($exception).'. SMTP-/Queue-Protokoll prüfen.']);
                throw $exception;
            }
        });
    }
}
