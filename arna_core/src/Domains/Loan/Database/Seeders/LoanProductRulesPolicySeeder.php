<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanProduct;

/**
 * Seeds the versioned loan_product_rules policy and attaches it to products; approval requires an attached policy version.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Database\Seeders
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