<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;

/**
 * LoanProductRulesPolicySeeder
 *
 * Seeds a `loan_product_rules` policy version and attaches it to every
 * seeded loan product that does not yet have one. Unblocks loan
 * approval: without a policy version attached, a loan under the product
 * can be drafted, submitted, and reviewed, but cannot be approved
 * (LoanApprovalService A.29 throws a ValidationException).
 *
 * =============================================================================
 * Relationship to LoanProductsSeeder
 * =============================================================================
 * This seeder is the second half of a two-part seed:
 *
 *   1. `LoanProductsSeeder` creates the three catalogue products per
 *      tenant. Each product carries `product_rules_policy_version_id =
 *      NULL`, because the policy version does not exist yet at that
 *      point.
 *
 *   2. THIS seeder creates the `versioned_policies` row of type
 *      `loan_product_rules` and updates every product whose
 *      `product_rules_policy_version_id` is still NULL to point at it.
 *
 * Run order: LoanProductsSeeder first, then this one. Running them in
 * the other order is harmless (this seeder will create the policy
 * version, find no products, and report zero updates), and running the
 * first seeder again after this one is also harmless (its
 * `firstOrCreate` semantics leave the attached policy id intact — it
 * does not touch products that already exist).
 *
 * =============================================================================
 * The versioned_policies schema (verified)
 * =============================================================================
 * From `DESCRIBE versioned_policies`:
 *
 *   id              bigint unsigned  auto_increment primary key
 *   policy_type     varchar(64)      NOT NULL, indexed
 *   version         int unsigned     NOT NULL
 *   effective_from  datetime         NOT NULL
 *   effective_until timestamp        NULL
 *   payload         json             NOT NULL
 *   created_at      timestamp        NULL
 *
 * Notes:
 *
 *   · There is NO `tenant_id` column. Policies are installation-wide,
 *     not per-tenant. This seeder therefore creates ONE policy version
 *     shared by every tenant's products. Multi-tenant policy isolation
 *     would require a migration on this table; that is a future CR.
 *
 *   · There is NO `updated_at` column. Policies are treated as
 *     immutable version records: a change is a NEW row with a higher
 *     `version`, not an UPDATE of an existing row. This mirrors the
 *     pattern used by the Loan domain's own immutable tables
 *     (loan_restructurings, loan_write_offs, loan_status_histories).
 *
 *   · `effective_until` is nullable. A row with `effective_until IS
 *     NULL` is the currently active version of that policy type. A row
 *     with a non-null `effective_until` is a superseded version,
 *     retained for audit.
 *
 * =============================================================================
 * The seeded payload
 * =============================================================================
 * The payload is a JSON object with three sections. Each section and
 * each key is required by the read sites documented below; a missing
 * key produces a `LogicException` at the read site (a data defect the
 * service treats as a programming error, not a caller error).
 *
 *   interest
 *     annual_rate              decimal string, e.g. "0.12" for 12%.
 *                              Read by LoanScheduleService (A.27) via
 *                              LoanPolicyKeys::INTEREST_ANNUAL_RATE.
 *
 *   term
 *     principal_grace_months   non-negative integer. Read by
 *                              LoanScheduleService.
 *     interest_grace_months    non-negative integer. MUST be 0 in Phase
 *                              2C; LoanScheduleService throws a
 *                              ValidationException for any non-zero
 *                              value (interest grace is deferred).
 *
 *   arrears
 *     enabled                  boolean. MUST be true for arrears
 *                              detection to run; LoanArrearsService
 *                              (A.33) throws if false.
 *     basis                    string. MUST be "overdue_installment" in
 *                              Phase 2C; LoanArrearsService throws for
 *                              any other value (per D133).
 *     grace_days               non-negative integer. Days after the
 *                              installment's due date before a penalty
 *                              applies. Read by DetectArrearsCommand
 *                              to filter eligible installments.
 *     penalty_rate             decimal string, e.g. "0.05" for 5%.
 *                              Read by LoanArrearsService via
 *                              LoanPolicyKeys::ARREARS_PENALTY_RATE.
 *     cap                      decimal string or null. Optional
 *                              absolute maximum on a computed penalty.
 *                              Null means "no cap".
 *
 * The values below are deliberate starting values, not business
 * decisions. They are chosen so that a loan originated under these
 * products has a well-defined and testable financial lifecycle:
 *
 *   12% annual interest, no grace periods, 5-day arrears grace, 5%
 *   penalty on the overdue installment amount, no cap.
 *
 * A tenant whose lending policy differs should publish a new
 * `loan_product_rules` version (a new row with a higher `version`)
 * rather than edit this seeder. See §"Superseding the seeded policy".
 *
 * =============================================================================
 * Idempotency
 * =============================================================================
 * The seeder is idempotent and non-destructive:
 *
 *   POLICY VERSION
 *     The seeder looks for an existing `loan_product_rules` row that
 *     is currently active (`effective_until IS NULL` AND
 *     `effective_from <= now()`). If one exists, it reuses it. If none
 *     exists, it creates a new row with `version = MAX(version) + 1`
 *     for this policy_type.
 *
 *     Consequence: running the seeder twice produces exactly one
 *     policy version. A product update in between (see below) is not
 *     undone.
 *
 *   PRODUCT ATTACHMENT
 *     For every product with `product_rules_policy_version_id IS NULL`,
 *     the seeder sets it to the policy version's id. Products whose
 *     `product_rules_policy_version_id` is already non-null are left
 *     unchanged.
 *
 *     Consequence: a product whose policy was deliberately attached to
 *     a specific version by an operator is not overwritten. A product
 *     that was seeded by `LoanProductsSeeder` and never touched is
 *     wired up on the first run of this seeder.
 *
 *   RERUN BEHAVIOR
 *     Running this seeder a third, fourth, or Nth time is a no-op once
 *     every product has a policy version. It does not create additional
 *     policy versions and does not reassign products.
 *
 * =============================================================================
 * Superseding the seeded policy
 * =============================================================================
 * To publish a new `loan_product_rules` version, an operator (or a
 * future admin UI) would:
 *
 *   1. INSERT a new row into `versioned_policies` with:
 *        policy_type     = 'loan_product_rules'
 *        version         = (MAX(version) + 1) for that type
 *        effective_from  = now()
 *        effective_until = NULL
 *        payload         = the new rules
 *   2. UPDATE the previous active row's `effective_until` to now().
 *   3. Optionally UPDATE loan products' `product_rules_policy_version_id`
 *      to the new row's id (products do not require this unless they
 *      want new originations to be governed by the new rules).
 *
 * Loans already in flight retain their `frozen_policy_version_id`
 * (copied at approval time), so a policy change does not retroactively
 * affect existing loans. That is the B6 freeze.
 *
 * This seeder does NOT perform step 2 or 3 automatically; it only
 * creates a version if none is active.
 *
 * =============================================================================
 * What this seeder does NOT do
 * =============================================================================
 *   · It does NOT create tenants. It iterates existing tenants only
 *     indirectly — the products it updates belong to whatever tenants
 *     already have products.
 *
 *   · It does NOT create products. See LoanProductsSeeder.
 *
 *   · It does NOT touch loan rows, installments, accruals, or
 *     obligations. Those are per-loan runtime artifacts.
 *
 *   · It does NOT touch product rows that already have a non-null
 *     `product_rules_policy_version_id`.
 *
 *   · It does NOT validate that the payload keys match a schema; the
 *     read sites (LoanScheduleService, LoanArrearsService) enforce
 *     their own invariants at read time.
 *
 *   · It does NOT publish superseded versions. See §"Superseding the
 *     seeded policy".
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 *   1. Save this file at the path above.
 *   2. In Plesk's Composer panel, run "Dump autoload".
 *   3. In Plesk's artisan panel, run:
 *
 *        db:seed --class=MaaleArna\MASES\Core\Domains\Loan\Database\Seeders\LoanProductRulesPolicySeeder
 *
 *      Note: no surrounding quotes, single backslashes.
 *   4. Verify with:
 *
 *        SELECT id, policy_type, version, effective_from, effective_until
 *        FROM versioned_policies
 *        WHERE policy_type = 'loan_product_rules';
 *
 *        SELECT id, code, product_rules_policy_version_id
 *        FROM loan_products;
 *
 *      Every product should have a non-null
 *      product_rules_policy_version_id matching the policy version's
 *      id.
 *   5. A loan under any product can now be approved.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130 (product
 *                                                  catalogue), B6 (hybrid
 *                                                  policy storage),
 *                                                  D132 (grace periods),
 *                                                  D133 (arrears rules)
 *   Phase 2C Clarification Response, 2026-09-12  — Extra 2 (single
 *                                                  policy type)
 *   Phase 2C A.6                                 — `AmortizationCalculator`
 *   Phase 2C A.7                                 — `LoanPolicyKeys` (the
 *                                                  key names this
 *                                                  payload uses)
 *   Phase 2C A.8                                 — `loan_products`
 *                                                  migration
 *   Phase 2C A.17                                — `LoanProduct`
 *   Phase 2C A.27                                — `LoanScheduleService`
 *                                                  (reads interest and
 *                                                  term sections)
 *   Phase 2C A.29                                — `LoanApprovalService`
 *                                                  (requires the policy
 *                                                  version at approval)
 *   Phase 2C A.33                                — `LoanArrearsService`
 *                                                  (reads arrears
 *                                                  section)
 *   Core Policy CORE.3                           — `VersionedPolicy` (the
 *                                                  model this seeder
 *                                                  writes rows for)
 *   Step 5 (manifest) — new file, no manifest
 *                        identifier. Sibling
 *                        to LoanProductsSeeder
 *                        (also new).
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Database\Seeders
 * @version 1.0.0
 */
final class LoanProductRulesPolicySeeder extends Seeder
{
    /**
     * The `policy_type` value for loan product rules.
     *
     * Matches the `EXPECTED_POLICY_TYPE` constant on
     * `LoanScheduleService` (A.27) and `LoanRestructuringService`
     * (A.34). A mismatch would be a `LogicException` at the read site.
     *
     * @var string
     */
    private const POLICY_TYPE = 'loan_product_rules';

    /**
     * The default payload for a `loan_product_rules` policy version.
     *
     * See the class docblock §"The seeded payload" for the key-by-key
     * documentation of what each value is read by and why. Values are
     * starting points, not business decisions; a tenant whose lending
     * policy differs should publish a new version rather than edit
     * this constant.
     *
     * @var array{
     *     interest: array{annual_rate: string},
     *     term: array{principal_grace_months: int, interest_grace_months: int},
     *     arrears: array{
     *         enabled: bool,
     *         basis: string,
     *         grace_days: int,
     *         penalty_rate: string,
     *         cap: string|null,
     *     },
     * }
     */
    private const PAYLOAD = [
        'interest' => [
            'annual_rate' => '0.12',
        ],
        'term' => [
            'principal_grace_months' => 0,
            'interest_grace_months'  => 0,
        ],
        'arrears' => [
            'enabled'      => true,
            'basis'        => 'overdue_installment',
            'grace_days'   => 5,
            'penalty_rate' => '0.05',
            'cap'          => null,
        ],
    ];

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Run the seeder.
     *
     * Ensures exactly one active `loan_product_rules` policy version
     * exists, then attaches it to every product whose
     * `product_rules_policy_version_id` is null.
     *
     * See the class docblock §"Idempotency" for the full semantics.
     *
     * @return void
     */
    public function run(): void
    {
        $policyId = $this->ensureActivePolicyVersion();

        $this->command->info(sprintf(
            'LoanProductRulesPolicySeeder: active policy version id=%d (%s, v%d).',
            $policyId,
            self::POLICY_TYPE,
            (int) DB::table('versioned_policies')
                ->where('id', $policyId)
                ->value('version'),
        ));

        $attached = $this->attachPolicyToProducts($policyId);

        if ($attached === 0) {
            $this->command->info(
                'LoanProductRulesPolicySeeder: no products needed '
                . 'attachment (all products already carry a policy '
                . 'version, or no products exist yet).'
            );
        } else {
            $this->command->info(sprintf(
                'LoanProductRulesPolicySeeder: attached the policy '
                . 'version to %d product(s).',
                $attached,
            ));
        }

        $this->command->info(
            'LoanProductRulesPolicySeeder: complete. Loans under these '
            . 'products can now be approved.'
        );
    }

    // =========================================================================
    // Private — policy version resolution
    // =========================================================================

    /**
     * Ensure exactly one active `loan_product_rules` policy version
     * exists. Returns its id.
     *
     * "Active" means `effective_until IS NULL` AND `effective_from <=
     * now()`. If multiple active rows exist (which would be a data
     * defect the seeder does not create, but that an out-of-band edit
     * could), the newest by `version` is returned.
     *
     * If no active row exists, a new one is created with
     * `version = MAX(version) + 1` for this policy_type. The `version`
     * column is monotonic per policy_type; the seeder relies on that
     * invariant rather than on `id` ordering.
     *
     * @return int  The policy version's primary key.
     */
    private function ensureActivePolicyVersion(): int
    {
        $existing = DB::table('versioned_policies')
            ->where('policy_type', self::POLICY_TYPE)
            ->whereNull('effective_until')
            ->where('effective_from', '<=', now())
            ->orderByDesc('version')
            ->first(['id']);

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $nextVersion = ((int) DB::table('versioned_policies')
            ->where('policy_type', self::POLICY_TYPE)
            ->max('version')) + 1;

        $payloadJson = json_encode(
            self::PAYLOAD,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        return (int) DB::table('versioned_policies')->insertGetId([
            'policy_type'     => self::POLICY_TYPE,
            'version'         => $nextVersion,
            'effective_from'  => now(),
            'effective_until' => null,
            'payload'         => $payloadJson,
            'created_at'      => now(),
        ]);
    }

    // =========================================================================
    // Private — product attachment
    // =========================================================================

    /**
     * Attach the policy version to every product whose
     * `product_rules_policy_version_id` is null.
     *
     * Products whose `product_rules_policy_version_id` is already
     * non-null are left untouched — see the class docblock
     * §"Idempotency".
     *
     * @param int $policyId
     * @return int  The number of products updated.
     */
    private function attachPolicyToProducts(int $policyId): int
    {
        $products = LoanProduct::query()
            ->whereNull('product_rules_policy_version_id')
            ->get();

        if ($products->isEmpty()) {
            return 0;
        }

        $updated = 0;

        foreach ($products as $product) {
            $product->product_rules_policy_version_id = $policyId;
            $product->save();
            $updated++;
        }

        return $updated;
    }
}