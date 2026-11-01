<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use MaaleArna\MASES\Core\Calendar\Services\CalendarReminderDispatcher;

/*
|--------------------------------------------------------------------------
| MASES Console Routes
|--------------------------------------------------------------------------
|
| Loaded by the console kernel AFTER every service provider registered in
| bootstrap/providers.php has been registered and booted.
|
| Responsibilities:
|
|   1. Closure commands (via the Artisan facade).
|
|   2. Scheduled task declarations (via the Schedule facade).
|
| Class-based commands that live outside app/Console/Commands/ are NOT
| registered here. They are registered by their owning domain provider
| via `$this->commands([...])` in the provider's boot() method. See
| `LoanServiceProvider::boot()` for the Loan domain's two commands.
|
| -----------------------------------------------------------------------------
| Phase 2C C.4 — Loan domain scheduled tasks
| -----------------------------------------------------------------------------
|
| The Loan domain's two scheduled commands are registered by
| LoanServiceProvider::boot(). This file only schedules them.
|
|   loan:accrue-interest    AccrueInterestCommand  (A.87)
|   loan:detect-arrears     DetectArrearsCommand   (A.88)
|
| Both run daily. The schedule cadence is not the accrual cadence: the
| frozen Phase 2C fiscal model is ONE interest accrual per installment
| and ONE penalty per installment. See each command's class docblock.
|
| If scheduling is not yet wanted, delete the two `Schedule::command`
| lines below. The commands remain registered and manually invocable.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('loan:accrue-interest')->daily();
Schedule::command('loan:detect-arrears')->daily();
Schedule::command('public-content:publish-scheduled')->everyMinute()->withoutOverlapping();

Artisan::command('calendar:dispatch-reminders {--limit=100}', function (CalendarReminderDispatcher $dispatcher): int {
    $result = $dispatcher->dispatch((int) $this->option('limit'));
    $this->info(sprintf(
        'Calendar reminders: %d delivered, %d skipped, %d retried, %d failed.',
        $result['delivered'], $result['skipped'], $result['retried'], $result['failed'],
    ));

    return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
})->purpose('Dispatch due tenant-scoped Calendar reminder notifications');

Schedule::command('calendar:dispatch-reminders --limit=100')->everyMinute()->withoutOverlapping();