<?php

use App\Actions\ExpireStaleReservationsAction;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| BookNest: mark approved reservations whose 3-day hold has passed as
| "expired". Availability never depends on this (every availability query
| ignores holds past expires_at); it keeps the stored status accurate.
*/
Artisan::command('reservations:expire', function (ExpireStaleReservationsAction $expireStaleReservations) {
    $count = $expireStaleReservations->handle();

    $this->info("Marked {$count} lapsed reservation hold(s) as expired.");
})->purpose('Mark approved reservations whose hold period has ended as expired');

Schedule::command('reservations:expire')->hourly()->withoutOverlapping();
