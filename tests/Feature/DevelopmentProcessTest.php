<?php

it('includes the scheduler when starting the development processes', function () {
    $this->artisan('dev:list', ['--filter' => 'scheduler'])
        ->expectsOutputToContain('php artisan schedule:work')
        ->assertSuccessful();
});
