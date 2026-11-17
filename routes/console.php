<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
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



/*
 * P0.9 incident response: rotate only accounts whose stored password hash
 * matches the compromised legacy bootstrap credential. The secret is supplied
 * at runtime through ARNA_COMPROMISED_BOOTSTRAP_PASSWORD and is never emitted.
 * Dry-run is the default; --apply is required to rotate and revoke API tokens.
 */
Artisan::command('arna:rotate-compromised-bootstrap-credentials {--apply : Rotate matching credentials and revoke their API tokens}', function (): int {
    $compromisedPassword = getenv('ARNA_COMPROMISED_BOOTSTRAP_PASSWORD');

    if (!is_string($compromisedPassword) || $compromisedPassword === '') {
        $this->error('ARNA_COMPROMISED_BOOTSTRAP_PASSWORD must be set in the process environment.');
        return 1;
    }

    $apply = (bool) $this->option('apply');
    $matches = 0;
    $rotated = 0;
    $tokensRevoked = 0;
    $hasPasswordChangedAt = Schema::hasColumn('users', 'password_changed_at');
    $hasPersonalAccessTokens = Schema::hasTable('personal_access_tokens');

    User::query()->select(['id', 'password'])->chunkById(200, function ($users) use (
        $compromisedPassword,
        $apply,
        &$matches,
        &$rotated,
        &$tokensRevoked,
        $hasPasswordChangedAt,
        $hasPersonalAccessTokens,
    ): void {
        foreach ($users as $user) {
            if (!Hash::check($compromisedPassword, (string) $user->password)) {
                continue;
            }

            $matches++;

            if (!$apply) {
                continue;
            }

            DB::transaction(function () use (
                $user,
                $hasPasswordChangedAt,
                $hasPersonalAccessTokens,
                &$rotated,
                &$tokensRevoked,
            ): void {
                $user->password = Hash::make(Str::random(64));
                $user->remember_token = Str::random(60);
                if ($hasPasswordChangedAt) {
                    $user->password_changed_at = null;
                }
                $user->save();
                $rotated++;

                if ($hasPersonalAccessTokens) {
                    $tokensRevoked += DB::table('personal_access_tokens')
                        ->where('tokenable_type', $user->getMorphClass())
                        ->where('tokenable_id', $user->getKey())
                        ->delete();
                }
            });
        }
    });

    if (!$apply) {
        $this->info("Dry run complete: {$matches} account(s) match the compromised credential. No accounts or tokens were changed.");
        $this->comment('Re-run with --apply after confirming the target environment and recovery-email readiness.');
        return 0;
    }

    $this->info("Rotation complete: {$rotated} account(s) rotated; {$tokensRevoked} personal access token(s) revoked.");
    $this->comment('Affected users must establish a new password through the normal password-reset flow.');
    return 0;
})->purpose('Rotate accounts matching the compromised legacy bootstrap credential and revoke their API tokens');
