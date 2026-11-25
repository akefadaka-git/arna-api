<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * Idempotent per-tenant loan-product seeder; reruns preserve operator edits.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Database\Seeders
 * @version 1.0.0
 */
final class LoanProductsSeeder extends Seeder
{
    /**
     * The product codes seeded by this seeder.
     *
     * Exposed as a class constant so that a follow-up policy-version
     * seeder (or an operator script) can iterate them without
     * duplicating the strings.
     *
     * @var list<string>
     */
    public const PRODUCT_CODES = [
        'CONSUMER-01',
        'MICRO-01',
        'SME-01',
    ];

    /**
     * The currency every seeded product is denominated in.
     *
     * Change this constant — and only this constant — to seed products
     * in a different currency for a tenant whose Wallets are
     * denominated in a different currency.
     *
     * @var string
     */
    private const CURRENCY = 'ETB';

    /**
     * The installment frequency every seeded product uses.
     *
     * Phase 2C's LoanScheduleService supports only 'monthly'. A future
     * CR that introduces other frequencies would update this constant
     * and the seed catalogue below.
     *
     * @var string
     */
    private const FREQUENCY = 'monthly';

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Run the seeder.
     *
     * Iterates every tenant and, for each, ensures the three catalogue
     * products exist. Uses `firstOrCreate` keyed on `(tenant_id, code)`
     * so the operation is idempotent and non-destructive. See the
     * class docblock §"Idempotency".
     *
     * @return void
     */
    public function run(): void
    {
        $tenants = Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->command->warn(
                'LoanProductsSeeder: no tenants found. Nothing to seed.'
            );
            return;
        }

        $catalogue = $this->productCatalogue();

        foreach ($tenants as $tenant) {
            $created = 0;
            $preserved = 0;

            foreach ($catalogue as $spec) {
                /** @var LoanProduct $existing */
                $existing = LoanProduct::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $spec['code'])
                    ->first();

                if ($existing !== null) {
                    $preserved++;
                    continue;
                }

                LoanProduct::query()->create([
                    'tenant_id'                      => $tenant->id,
                    'code'                           => $spec['code'],
                    'name_am'                        => $spec['name_am'],
                    'name_en'                        => $spec['name_en'],
                    'description_am'                 => $spec['description_am'],
                    'description_en'                 => $spec['description_en'],
                    'purpose'                        => $spec['purpose'],
                    'currency'                       => self::CURRENCY,
                    'min_principal'                  => $spec['min_principal'],
                    'max_principal'                  => $spec['max_principal'],
                    'min_term_months'                => $spec['min_term_months'],
                    'max_term_months'                => $spec['max_term_months'],
                    'default_installment_frequency'  => self::FREQUENCY,
                    'is_active'                      => true,
                    'product_rules_policy_version_id' => null,
                ]);

                $created++;
            }

            $this->command->info(sprintf(
                'LoanProductsSeeder: tenant #%d (%s) — %d product(s) created, %d preserved.',
                $tenant->id,
                $tenant->slug ?? $tenant->name ?? '—',
                $created,
                $preserved,
            ));
        }

        $this->command->info(
            'LoanProductsSeeder: complete. Products without a policy '
            . 'version can be drafted and submitted; approving a loan '
            . 'under them requires attaching a loan_product_rules '
            . 'policy version first.'
        );
    }

    // =========================================================================
    // Private — the catalogue
    // =========================================================================

    /**
     * The product catalogue seeded per tenant.
     *
     * Each entry is a plain array of the per-product values. The
     * constants `CURRENCY` and `FREQUENCY` above are applied uniformly
     * and are not repeated per entry.
     *
     * Monetary values are decimal strings at scale 4 to match the
     * `decimal(20,4)` columns. Term values are integers.
     *
     * @return list<array{
     *     code: string,
     *     name_am: string,
     *     name_en: string,
     *     description_am: string,
     *     description_en: string,
     *     purpose: string|null,
     *     min_principal: string,
     *     max_principal: string,
     *     min_term_months: int,
     *     max_term_months: int,
     * }>
     */
    private function productCatalogue(): array
    {
        return [
            // -----------------------------------------------------------------
            // CONSUMER-01 — small consumer loan.
            // -----------------------------------------------------------------
            [
                'code'           => 'CONSUMER-01',
                'name_am'        => 'የፍጆታ ብድር',
                'name_en'        => 'Consumer Loan',
                'description_am' => 'የፍጆታ ዕቃዎችን ለመግዛት የሚሰጥ አጭር ጊዜ ብድር።',
                'description_en' => 'Short-term loan for the purchase of consumer goods.',
                'purpose'        => null,
                'min_principal'  => '1000.0000',
                'max_principal'  => '50000.0000',
                'min_term_months' => 3,
                'max_term_months' => 36,
            ],

            // -----------------------------------------------------------------
            // MICRO-01 — micro-enterprise loan. Exercises the low end of
            // the principal envelope.
            // -----------------------------------------------------------------
            [
                'code'           => 'MICRO-01',
                'name_am'        => 'የጥቃቅን ንግድ ብድር',
                'name_en'        => 'Micro-Enterprise Loan',
                'description_am' => 'ለጥቃቅን ንግዶች የሚሰጥ አጭር ጊዜ ብድር።',
                'description_en' => 'Short-term loan for micro-enterprises.',
                'purpose'        => null,
                'min_principal'  => '500.0000',
                'max_principal'  => '10000.0000',
                'min_term_months' => 1,
                'max_term_months' => 12,
            ],

            // -----------------------------------------------------------------
            // SME-01 — small and medium enterprise loan. Exercises the
            // high end of the principal envelope.
            // -----------------------------------------------------------------
            [
                'code'           => 'SME-01',
                'name_am'        => 'የአነስተኛና መካከለኛ ኢንተርፕራይዝ ብድር',
                'name_en'        => 'SME Loan',
                'description_am' => 'ለአነስተኛና መካከለኛ ኢንተርፕራይዞች የሚሰጥ መካከለኛ ጊዜ ብድር።',
                'description_en' => 'Medium-term loan for small and medium enterprises.',
                'purpose'        => null,
                'min_principal'  => '50000.0000',
                'max_principal'  => '1000000.0000',
                'min_term_months' => 12,
                'max_term_months' => 60,
            ],
        ];
    }
}