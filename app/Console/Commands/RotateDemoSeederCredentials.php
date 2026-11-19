<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Data\BootstrapUsers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\AuthenticationService;

/**
 * Rotate credentials for accounts created by the legacy demo/bootstrap seeders.
 *
 * This explicit operational command is not part of normal deployment or
 * migration execution. It replaces each targeted password with an independent
 * random secret, invalidates remember tokens and Sanctum tokens, and clears
 * outstanding password-reset tokens so account holders must request a fresh
 * reset link through their verified email address.
 *
 * No password or reset token is displayed, logged, or returned.
 */
final class RotateDemoSeederCredentials extends Command
{
    protected $signature = 'auth:rotate-demo-seeder-credentials
                            {--force : Run without an interactive confirmation}';

    protected $description = 'Invalidate legacy shared demo/bootstrap credentials and their active tokens';

    public function handle(AuthenticationService $authentication): int
    {
        if (! Schema::hasTable('shareholders')
            || ! Schema::hasTable('personal_access_tokens')
            || ! Schema::hasTable('password_reset_tokens')) {
            $this->error('Required shareholder, token, or password-reset tables are missing; no credentials were changed.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Rotate seeded administrator and linked shareholder credentials and revoke their tokens?'
        )) {
            $this->warn('Credential rotation cancelled; no accounts were changed.');

            return self::FAILURE;
        }

        $shareholderUserIds = DB::table('shareholders')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->merge(
                DB::table('shareholders')
                    ->whereNotNull('representative_user_id')
                    ->pluck('representative_user_id')
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        $adminEmails = [
            BootstrapUsers::SUPER_ADMIN['email'],
            BootstrapUsers::PLATFORM_ADMIN['email'],
        ];

        $users = User::query()
            ->where(function ($query) use ($adminEmails, $shareholderUserIds): void {
                $query->whereIn('email', $adminEmails);

                if ($shareholderUserIds !== []) {
                    $query->orWhereIn('id', $shareholderUserIds);
                }
            })
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('No seeded administrator or linked shareholder accounts were found.');

            return self::SUCCESS;
        }

        $revokedTokens = 0;

        DB::transaction(function () use ($users, $authentication, &$revokedTokens): void {
            foreach ($users as $user) {
                $user->password = Hash::make(Str::random(64));
                $user->setRememberToken(Str::random(60));

                if (Schema::hasColumn('users', 'password_changed_at')) {
                    $user->password_changed_at = null;
                }

                $user->save();
                $revokedTokens += $authentication->revokeAllTokens($user);

                DB::table('password_reset_tokens')
                    ->where('email', $user->email)
                    ->delete();
            }
        });

        $this->info(sprintf(
            'Rotated %d seeded account(s); revoked %d Sanctum token(s). No credentials were displayed.',
            $users->count(),
            $revokedTokens,
        ));
        $this->line('Account holders must request a new password-reset link using their verified email address.');

        return self::SUCCESS;
    }
}
