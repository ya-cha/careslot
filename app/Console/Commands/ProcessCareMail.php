<?php

namespace App\Console\Commands;

use App\Jobs\SendCareEmail;
use App\Models\EmailDelivery;
use Illuminate\Console\Command;

class ProcessCareMail extends Command
{
    protected $signature = 'careslot:send-pending {--retry : Auch nach vier Fehlversuchen erneut einreihen}';

    protected $description = 'Reiht dauerhaft gespeicherte Versandaufträge in die Queue ein';

    public function handle(): int
    {
        EmailDelivery::whereNull('sent_at')->when(! $this->option('retry'), fn ($query) => $query->where('attempts', '<', 4))->orderBy('id')->each(function (EmailDelivery $delivery): void {
            if ($this->option('retry')) {
                $delivery->update(['attempts' => 0]);
            }
            SendCareEmail::dispatch($delivery->id);
        });

        return self::SUCCESS;
    }
}
