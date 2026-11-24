<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Database\Seeders;

use Illuminate\Database\Seeder;
use LogicException;

/**
 * Retired operational seeder.
 *
 * Loan products are tenant-owned financial configuration. They must be
 * created through the governed Loan Product Configuration workflow after
 * the product envelope has been reviewed and approved. This seeder used to
 * insert fixed product codes, ETB principal limits, and term ranges; those
 * values are business decisions and must not be embedded in source code.
 *
 * Kept as a fail-closed compatibility entry point so old deployment
 * instructions cannot silently recreate hard-coded products.
 */
final class LoanProductsSeeder extends Seeder
{
    public function run(): void
    {
        throw new LogicException(
            'LoanProductsSeeder is retired. Configure loan products through the governed product-configuration workflow; no default products are seeded.'
        );
    }
}
