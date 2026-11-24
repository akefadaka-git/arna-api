<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Database\Seeders;

use Illuminate\Database\Seeder;
use LogicException;

/**
 * Retired operational seeder.
 *
 * Interest, arrears grace, penalty basis/rate/caps, fees, and other loan
 * financial terms must be supplied through a reviewed and approved policy
 * configuration. They are stored as immutable versions in the Core
 * VersionedPolicyRegistry and attached to products only after approval.
 *
 * This seeder previously published an unapproved 12% annual rate, a
 * five-day grace period, and a 5% penalty. Those are business-policy
 * decisions, not safe software defaults. The class remains only as a
 * fail-closed compatibility entry point for old deployment instructions.
 */
final class LoanProductRulesPolicySeeder extends Seeder
{
    public function run(): void
    {
        throw new LogicException(
            'LoanProductRulesPolicySeeder is retired. No interest, grace-period, penalty, fee, or other financial terms are seeded; submit and approve a configured policy version instead.'
        );
    }
}
