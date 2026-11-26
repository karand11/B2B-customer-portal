<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Materialises expiry (expired is already the effective status at read time; this keeps stored data and history tidy).
Schedule::command('quotes:expire')->everyFiveMinutes()->withoutOverlapping();

// Materialises overdue (the API already reports the effective status; this keeps stored data and history tidy).
Schedule::command('invoices:mark-overdue')->hourly()->withoutOverlapping();
