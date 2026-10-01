<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * LoanProductsSeeder
 *
 * Seeds the tenant's loan product catalogue. Every tenant receives
 * three products covering the small, medium, and micro lending
 * envelopes. The seeder is idempotent: running it twice produces the
 * same state, and a rerun after operator edits leaves those edits
 * intact.
 *
 * =============================================================================
 * Why this seeder exists
 * =============================================================================
 * Phase 2C deliberately ships NO product-management UI. The frozen
 * Stage 6 §4.16 HTTP surface exposes no `POST /loans/products` and no
 * product-update route; the LoanProductPolicy (A.53) explicitly
 * documents that "products are seeded or created by administrative
 * scripts". This seeder is that administrative script for the
 * delivered Phase 2C scope.
 *
 * A tenant whose `loan_products` table is empty cannot originate a
 * loan: the loan application dialog's product dropdown is bound to
 * `GET /api/loans/products`, which returns an empty array, and the
 * dialog renders no options. This seeder unblocks that flow.
 *
 * =============================================================================
 * The three products
 * =============================================================================
 * Per tenant, the seeder inserts exactly three products:
 *
 *   · CONSUMER-01 — small consumer loan.
 *        1,000 – 50,000 ETB, 3 – 36 months, monthly installments.
 *
 *   · MICRO-01    — micro-enterprise loan.
 *        500 – 10,000 ETB, 1 – 12 months, monthly installments.
 *
 *   · SME-01      — small and medium enterprise loan.
 *        50,000 – 1,000,000 ETB, 12 – 60 months, monthly installments.
 *
 * The three cover distinct principal brackets so that a
 * `principal_amount` outside one product's envelope can be tested
 * against another. The MICRO-01 product exercises the low end of the
 * `isPrincipalWithinBounds()` check; the SME-01 product exercises the
 * upper end.
 *
 * All three products are denominated in ETB. The Q8 single-currency
 * invariant (LoanDisbursementService) requires a product's currency to
 * match the borrower's Wallet currency; the platform's Wallets are
 * ETB-denominated in the delivered configuration. A tenant whose
 * Wallets are denominated in a different currency needs its products
 * reseeded with that currency — the constants below are the single
 * place to change.
 *
 * All three products use `default_installment_frequency = 'monthly'`.
 * Phase 2C's LoanScheduleService supports no other frequency.
 *
 * =============================================================================
 * The `product_rules_policy_version_id` is NULL — and what that means
 * =============================================================================
 * Each seeded product has `product_rules_policy_version_id = NULL`.
 * This is deliberate and has one consequence worth documenting:
 *
 *   A loan under a product with a NULL policy version can be created
 *   (draft), submitted, and advanced up to `under_review`. It CANNOT
 *   be approved: `LoanApprovalService::freezePolicyAndRecordApproval()`
 *   (A.29) throws a ValidationException when the product has no policy
 *   version, because the loan's financial rules (interest rate, grace
 *   periods, arrears configuration) would be undefined.
 *
 * Attaching a policy version is a separate step. The operator attaches
 * it once a `versioned_policies` row of type `loan_product_rules`
 * exists for the tenant, either by:
 *
 *   · a follow-up policy-version seeder (planned; see the class
 *     docblock §"What this seeder does NOT do"), or
 *   · a direct UPDATE against `loan_products.product_rules_policy_version_id`
 *     for the desired product.
 *
 * =============================================================================
 * Idempotency — firstOrCreate, not updateOrCreate
 * =============================================================================
 * The seeder uses `firstOrCreate` keyed on `(tenant_id, code)`. On
 * every run, for every tenant, for every seeded product:
 *
 *   · If a row with the same (tenant_id, code) exists, it is left
 *     UNCHANGED. Operator edits to the name, bounds, description, or
 *     `is_active` flag are preserved.
 *
 *   · If no such row exists, it is created with the values below.
 *
 * The choice of `firstOrCreate` over `updateOrCreate` is deliberate.
 * An `updateOrCreate` would silently clobber operator edits — for
 * example, a product an operator deactivated for a specific reason
 * would be re-activated on the next seeder run. `firstOrCreate` is
 * idempotent in the sense that matters here: running it repeatedly
 * never produces a different state than running it once.
 *
 * A reviewer who wants the seed data to remain authoritative (reset
 * behavior) would swap `firstOrCreate` for `updateOrCreate`. The
 * docblock notes the trade-off so the choice is visible.
 *
 * =============================================================================
 * What this seeder does NOT do
 * =============================================================================
 *   · It does NOT create tenants. It reads the tenant table and seeds
 *     products for every existing tenant.
 *
 *   · It does NOT create `versioned_policies` rows. Attaching a
 *     `loan_product_rules` policy version is a separate step, and its
 *     schema (the `versioned_policies` table) is not part of the Loan
 *     domain.
 *
 *   · It does NOT create Wallets. Wallet provisioning is a Phase 2B
 *     concern; the loan flow assumes the borrower already has one.
 *
 *   · It does NOT create loans, installments, or obligations. Those
 *     are per-loan runtime artifacts, not seed data.
 *
 *   · It does NOT modify existing products. See §"Idempotency".
 *
 *   · It does NOT delete anything. Rerunning the seeder is a no-op on
 *     tenants whose products already exist.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 *   1. Save this file at the path above.
 *   2. In Plesk's Composer panel, run "Dump autoload" so the new class
 *      enters the optimized classmap. (The project sets
 *      `"optimize-autoloader": true`; a new class is invisible to the
 *      autoloader until the classmap is refreshed.)
 *   3. In Plesk's artisan panel, run:
 *
 *        db:seed --class=MaaleArna\MASES\Core\Domains\Loan\Database\Seeders\LoanProductsSeeder
 *
 *      Note: no surrounding quotes, single backslashes. The class name
 *      is the fully-qualified class, not a file path.
 *   4. Reload the loan application dialog. The product dropdown now
 *      shows three options.
 *
 * The seeder can be run repeatedly; re-running is a no-op for tenants
 * whose products already exist.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130 (product
 *                                                  catalogue), B6 (hybrid
 *                                                  policy storage)
 *   Phase 2C Clarification Response, 2026-09-12  — Extra 2 (single
 *                                                  policy type)
 *   Phase 2C A.8                                 — `loan_products`
 *                                                  migration (the column
 *                                                  set seeded here)
 *   Phase 2C A.17                                — `LoanProduct` (the
 *                                                  model)
 *   Phase 2C A.29                                — `LoanApprovalService`
 *                                                  (the service that
 *                                                  requires a policy
 *                                                  version at approval)
 *   Phase 2C A.30                                — `LoanDisbursementService`
 *                                                  (the service that
 *                                                  enforces the Q8
 *                                                  currency invariant)
 *   Phase 2C A.53                                — `LoanProductPolicy`
 *                                                  (the policy that
 *                                                  documents products as
 *                                                  seeded reference data)
 *   Phase 2C A.86                                — `LoanPermissionsSeeder`
 *                                                  (the sibling seeder
 *                                                  this file mirrors)
 *   Phase 2C A.87                                — `LoanRolesSeeder`
 *                                                  (the sibling seeder
 *                                                  this file mirrors)
 *   Phase 2B migration 2B.49                     — `wallets` (the
 *                                                  currency-match source
 *                                                  at disbursement)
 *   Step 5 (manifest) — a new file delivered outside the original
 *                        manifest; the LoanProductsSeeder was not
 *                        listed. Its siblings (A.86, A.87) are the
 *                        nearest precedent.
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