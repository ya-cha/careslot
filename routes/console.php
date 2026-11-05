<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('careslot:close-responses')->everyMinute()->withoutOverlapping();
Schedule::command('careslot:send-pending')->everyMinute()->withoutOverlapping();
