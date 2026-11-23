<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\BootstrapUsers;
use Database\Seeders\Data\ShareholderRegister;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Domains\Governance\Database\Seeders\GovernanceElectionCommitteePermissionsSeeder;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareholderNumberReconciler;
use RuntimeException;

/**
 * Production-safe core bootstrap.
 *
 * Seeds the canonical tenant, application and authorization catalogues,
 * the two bootstrap administrators, and the 100-row shareholder register.
 * Unlike MasesCoreSeeder, this orchestrator never clears users, shareholders,
 * pivots, tokens, numbering reservations, or other existing records.
 *
 * Run through DatabaseSeeder (php artisan db:seed --force) after migrations.
 * All constituent upserts must remain safe to rerun. Destructive demo reset
 * remains isolated behind MasesCoreSeeder and DemoSeederGuard.
 */
final class ProductionCoreSeeder extends Seeder
{
    public function run(): void
    {
        $this->assertRequiredSchema();

        $tenant = (new TenantSeeder())->seed();
        $applications = (new ApplicationSeeder())->seed();
        $permissions = (new PermissionSeeder())->seed($applications);

        app(GovernanceElectionCommitteePermissionsSeeder::class)->run();

        $roles = (new RoleSeeder())->seed($tenant, $applications);
        (new RolePermissionSeeder())->seed($roles, $permissions);

        $admins = (new AdminUserSeeder())->seed($tenant, $roles);
        $shareholderSeeder = new ShareholderSeeder();
        $shareholders = $shareholderSeeder->seed($tenant, $roles, $admins['super']);

        app(ShareholderNumberReconciler::class)->reconcile();

        $this->assertSeededState(
            (int) $tenant->id,
            $shareholderSeeder->seededAccountEmails(),
            count($shareholders),
        );

        if ($this->command !== null) {
            $this->command->info('ARNA production-safe core bootstrap completed.');
            $this->command->line('  Tenant:       ' . $tenant->slug);
            $this->command->line('  Admins:       2');
            $this->command->line('  Shareholders: ' . count($shareholders));
            $this->command->line('  Expected identities: 102');
            $this->command->line('  Shareholder numbers, reservations, and legacy aliases reconciled.');
            $this->command->line('  Existing users, shareholder records, pivots, and tokens were not cleared.');
            $this->command->line('  Newly created accounts receive random credentials; no passwords are printed.');
        }
    }

    private function assertRequiredSchema(): void
    {
        $requiredTables = [
            'users',
            'tenants',
            'platform_applications',
            'permissions',
            'roles',
            'user_has_roles',
            'shareholders',
            'shareholder_number_reservations',
            'shareholder_legacy_aliases',
            'shareholder_number_sequences',
        ];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "ProductionCoreSeeder requires the {$table} table. Run and verify all migrations before seeding."
                );
            }
        }

        foreach (['tenant_id', 'shareholder_type', 'user_id', 'representative_user_id', 'shareholder_number'] as $column) {
            if (! Schema::hasColumn('shareholders', $column)) {
                throw new RuntimeException(
                    "ProductionCoreSeeder requires shareholders.{$column}. The database schema is incomplete."
                );
            }
        }
    }

    /**
     * Fail closed if the requested bootstrap cohort was only partially written.
     *
     * @param list<string> $shareholderEmails
     */
    private function assertSeededState(int $tenantId, array $shareholderEmails, int $seededShareholders): void
    {
        $expectedEmails = array_merge(
            [BootstrapUsers::SUPER_ADMIN['email'], BootstrapUsers::PLATFORM_ADMIN['email']],
            $shareholderEmails,
        );

        if (count($expectedEmails) !== 102 || $seededShareholders !== count(ShareholderRegister::ROWS)) {
            throw new RuntimeException(
                'ProductionCoreSeeder expected exactly 2 administrator identities and 100 shareholder identities.'
            );
        }

        $identityCount = DB::table('users')
            ->whereIn('email', $expectedEmails)
            ->distinct()
            ->count('email');

        if ($identityCount !== 102) {
            throw new RuntimeException(
                "ProductionCoreSeeder expected 102 persisted identities; found {$identityCount}."
            );
        }

        $shareholderUserIds = DB::table('users')
            ->whereIn('email', $shareholderEmails)
            ->pluck('id');

        if ($shareholderUserIds->count() !== 100) {
            throw new RuntimeException(
                "ProductionCoreSeeder expected 100 shareholder user identities; found {$shareholderUserIds->count()}."
            );
        }

        $registerRows = DB::table('shareholders')
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($shareholderUserIds): void {
                $query->whereIn('user_id', $shareholderUserIds)
                    ->orWhereIn('representative_user_id', $shareholderUserIds);
            })
            ->get(['id', 'shareholder_type', 'user_id', 'representative_user_id', 'shareholder_number']);

        if ($registerRows->count() !== 100) {
            throw new RuntimeException(
                "ProductionCoreSeeder expected 100 register rows for the seeded shareholder identities; found {$registerRows->count()}."
            );
        }

        $unlinked = $registerRows->filter(
            fn (object $row): bool => $row->user_id === null && $row->representative_user_id === null
        )->count();

        $doubleLinked = $registerRows->filter(
            fn (object $row): bool => $row->user_id !== null && $row->representative_user_id !== null
        )->count();

        $invalidIndividualLinks = $registerRows->filter(
            fn (object $row): bool => $row->shareholder_type === 'individual'
                && ($row->user_id === null || $row->representative_user_id !== null)
        )->count();

        $invalidEntityLinks = $registerRows->filter(
            fn (object $row): bool => $row->shareholder_type !== 'individual'
                && ($row->user_id !== null || $row->representative_user_id === null)
        )->count();

        $unnumbered = $registerRows->filter(
            fn (object $row): bool => $row->shareholder_number === null || $row->shareholder_number === ''
        )->count();

        if ($unlinked !== 0 || $doubleLinked !== 0 || $invalidIndividualLinks !== 0
            || $invalidEntityLinks !== 0 || $unnumbered !== 0) {
            throw new RuntimeException(
                "ProductionCoreSeeder found invalid shareholder links or missing permanent numbers (unlinked={$unlinked}, double_linked={$doubleLinked}, invalid_individual_links={$invalidIndividualLinks}, invalid_entity_links={$invalidEntityLinks}, unnumbered={$unnumbered})."
            );
        }

        $roleAssignedUsers = DB::table('user_has_roles')
            ->whereIn('user_id', $shareholderUserIds)
            ->distinct()
            ->count('user_id');

        if ($roleAssignedUsers !== 100) {
            throw new RuntimeException(
                "ProductionCoreSeeder expected all 100 shareholder accounts to have at least one role; found {$roleAssignedUsers}."
            );
        }

        $shareholderIds = $registerRows->pluck('id');

        $reservationCount = DB::table('shareholder_number_reservations')
            ->where('tenant_id', $tenantId)
            ->whereIn('shareholder_id', $shareholderIds)
            ->distinct()
            ->count('shareholder_id');

        $aliasCount = DB::table('shareholder_legacy_aliases')
            ->where('tenant_id', $tenantId)
            ->whereIn('shareholder_id', $shareholderIds)
            ->distinct()
            ->count('shareholder_id');

        if ($reservationCount !== 100 || $aliasCount !== 100) {
            throw new RuntimeException(
                "ProductionCoreSeeder expected 100 permanent number reservations and 100 legacy aliases; found reservations={$reservationCount}, aliases={$aliasCount}."
            );
        }
    }
}
