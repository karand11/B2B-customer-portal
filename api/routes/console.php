<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Materialises expiry (expired is already the effective status at read time; this keeps stored data and history tidy).
Schedule::command('quotes:expire')->everyFiveMinutes()->withoutOverlapping();

// Materialises overdue (the API already reports the effective status; this keeps stored data and history tidy).
Schedule::command('invoices:mark-overdue')->hourly()->withoutOverlapping();

// Expires import previews older than an hour and deletes their files.
Schedule::command('imports:prune')->hourly()->withoutOverlapping();

// Phase 7 operations (docs/phase-7-design.md 4.5). Needs the scheduler cron (`* * * * * php artisan schedule:run`) in production.
// Heartbeat: `portal:health --strict` and the monitoring read it; a late cron never takes a node out of rotation (readiness ignores it).
Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()->timestamp, 3600))->name('scheduler-heartbeat')->everyMinute();

// Logs `Queue backlog` (QueueBusy listener in AppServiceProvider) when more than 100 jobs wait.
Schedule::command('queue:monitor database:default --max=100')->everyFiveMinutes();

// Failed jobs are kept 30 days for diagnosis.
Schedule::command('queue:prune-failed --hours=720')->daily();

// Read-only form: report and log warnings (orphan files, rows without file, company near its cap, low disk). Never deletes.
Schedule::command('quotes:storage-check')->daily();
