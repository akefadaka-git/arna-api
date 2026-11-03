<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSitePage;

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
Schedule::command('corporate-site:publish-scheduled')->everyMinute()->withoutOverlapping();

Artisan::command('corporate-site:publish-scheduled', function (): int {
    $published = DB::transaction(function (): int {
        $pages = CorporateSitePage::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(100)
            ->get();

        foreach ($pages as $page) {
            $old = ['status' => $page->status, 'version' => $page->version];
            $page->status = 'published';
            $page->published_at = now();
            $page->version++;
            $page->save();

            AuditLog::create([
                'user_id' => null,
                'tenant_id' => null,
                'action' => 'corporate_site_page.scheduled_publish',
                'resource_type' => 'corporate_site_page',
                'resource_id' => (string) $page->id,
                'old_values' => $old,
                'new_values' => ['status' => 'published', 'version' => $page->version],
                'ip_address' => null,
                'user_agent' => null,
                'request_id' => null,
                'metadata' => ['public_id' => $page->public_id],
            ]);
        }

        return $pages->count();
    });

    $this->info("Published {$published} scheduled corporate page(s).");
    return 0;
})->purpose('Publish approved corporate pages whose schedule time has arrived');

