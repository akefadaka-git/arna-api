<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\Arna\Core\Authorization\Models\Permission;

/**
 * LoanPermissionsSeeder
 *
 * Seeds the fifteen Loan-domain permissions into the platform's
 * permission table.
 *
 * =============================================================================
 * Keep permission slugs stable and seed permissions before role grants.
 * @package MaaleArna\Arna\Core\Domains\Loan\Database\Seeders
 * @version 1.1.0
 */
final class LoanPermissionsSeeder extends Seeder
{
    /**
     * The fifteen permissions, keyed by slug.
     *
     * Each value is the bilingual label pair:
     *
     *     name_am — the display name in Amharic. Primary per §2.14.
     *     name_en — the display name in Latin script. Fallback.
     *
     * The array's order matches the order documented in the class
     * docblock §"The permission slugs and their display names" — the
     * frozen B7 order, reproduced for administrative readability. The
     * order is presentation-only; no logic depends on it.
     *
     * @var array<string, array{name_am: string, name_en: string}>
     */
    private const PERMISSIONS = [
        'loan.view' => [
            'name_am' => 'ብድሮችን ማየት',
            'name_en' => 'View loans',
        ],
        'loan.view_own' => [
            'name_am' => 'የራስን ብድሮች ማየት',
            'name_en' => 'View own loans',
        ],
        'loan.apply' => [
            'name_am' => 'ብድር ማመልከት',
            'name_en' => 'Apply for a loan',
        ],
        'loan.approve' => [
            'name_am' => 'ብድር ማጽደቅ',
            'name_en' => 'Approve a loan',
        ],
        'loan.reject' => [
            'name_am' => 'ብድር ውድቅ ማድረግ',
            'name_en' => 'Reject a loan',
        ],
        'loan.disburse' => [
            'name_am' => 'ብድር መልቀቅ',
            'name_en' => 'Disburse a loan',
        ],
        'loan.repay' => [
            'name_am' => 'የብድር ክፍያ መመዝገብ',
            'name_en' => 'Record a loan repayment',
        ],
        'loan.restructure' => [
            'name_am' => 'ብድር እንደገና ማዋቀር',
            'name_en' => 'Restructure a loan',
        ],
        'loan.writeoff' => [
            'name_am' => 'ብድር መሰረዝ',
            'name_en' => 'Write off a loan',
        ],
        'loan.schedule.view' => [
            'name_am' => 'የብድር መርሐግብር ማየት',
            'name_en' => 'View a loan\'s schedule',
        ],
        'loan.statement.view' => [
            'name_am' => 'የብድር መግለጫ ማየት',
            'name_en' => 'View a loan\'s statement',
        ],
        'loan.statement.view_own' => [
            'name_am' => 'የራስን የብድር መግለጫ ማየት',
            'name_en' => 'View own loan statements',
        ],
        'loan.collateral.view' => [
            'name_am' => 'የብድር ዋስትና ማየት',
            'name_en' => 'View loan collateral',
        ],
        'loan.collateral.manage' => [
            'name_am' => 'የብድር ዋስትና መመዝገብ',
            'name_en' => 'Register loan collateral',
        ],
        'loan.collateral.release' => [
            'name_am' => 'የብድር ዋስትና መልቀቅ',
            'name_en' => 'Release loan collateral',
        ],
    ];

    /**
     * The `category` value recorded on every Loan permission.
     *
     * Matches the sample fixtures' convention (the Shareholder fixtures
     * use `'shareholder'`, the Certificate fixtures use `'certificate'`,
     * the Wallet fixtures use `'wallet'`).
     */
    private const CATEGORY = 'loan';

    /**
     * The `scope` value recorded on every Loan permission.
     *
     * `'tenant'` — every Loan permission is tenant-scoped, matching the
     * sample fixtures' convention.
     */
    private const SCOPE = 'tenant';

    /**
     * Run the seeder.
     *
     * Every permission in the frozen list is upserted by slug. The
     * operation is idempotent: a re-run updates the display names and
     * the metadata in place and preserves the row's primary key, so any
     * existing role-permission pivot row remains valid.
     *
     * @return void
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $labels) {
            Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name_am'        => $labels['name_am'],
                    'name_en'        => $labels['name_en'],

                    // Reuse-name policy: the description mirrors the name
                    // in both languages. Amharic is primary per §2.14.
                    'description_am' => $labels['name_am'],
                    'description_en' => $labels['name_en'],

                    'category'       => self::CATEGORY,
                    'scope'          => self::SCOPE,
                    'tenant_id'      => null,
                    'application_id' => null,
                    'is_active'      => true,
                    'is_system'      => true,
                ],
            );
        }

        $this->command->info(
            'Loan permissions seeded: ' . count(self::PERMISSIONS) . ' entries (Amharic-first).'
        );
    }
}