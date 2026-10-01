<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanReconciliationTest
 *
 * Feature tests for the loan-reconciliation HTTP surface:
 *
 *     GET /loans/{loan}/reconciliation    show    — the C5 reconciliation report
 *
 * =============================================================================
 * File placement
 * =============================================================================
 * Per the platform-wide convention (see 2A.48, 2A.49, 2B.62–2B.66 and the
 * Loan domain's unit suites A.90–A.101), this file lives INSIDE the Loan
 * module at `mases_core/src/Domains/Loan/Tests/Feature/` and carries the
 * namespace `MaaleArna\MASES\Core\Domains\Loan\Tests\Feature`.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The reconciliation endpoint is READ-ONLY. The service (A.36) opens no
 * transaction, writes no rows, and dispatches no events. The feature
 * tests do not assert on any after-commit callback.
 *
 * `RefreshDatabase` is therefore the correct default per §2.13 and the
 * pattern followed by the Shareholder and Wallet feature suites, and by
 * `Wallet\Tests\Unit\StatementServiceTest` (2B.61).
 *
 * =============================================================================
 * Authorization — the SHARED `view` ability
 * =============================================================================
 * The endpoint uses `LoanPolicy::view()` (A.52), NOT a dedicated
 * reconciliation-specific ability. The frozen design (documented on
 * A.52 and A.83) treats the reconciliation report as a view of the
 * loan's financial state, not a separate authorization concept.
 *
 * The ability grants access to a caller who:
 *   - holds `loan.view` (tenant-wide), OR
 *   - holds `loan.view_own` AND is the borrower of the loan.
 *
 * Both paths are exercised below.
 *
 * =============================================================================
 * The frozen C5 invariant
 * =============================================================================
 * The report answers the exact invariant frozen in the Phase 2C Final
 * Reconciliation §3:
 *
 *     Σ(wallet_obligations.outstanding_amount
 *         WHERE reference_type = 'Loan'
 *           AND reference_id   = (string) loans.id)
 *       ==
 *     loans.outstanding_principal
 *       + loans.outstanding_interest
 *       + loans.outstanding_penalty
 *
 * The C5 correction EXPLICITLY forbids comparing against
 * `wallets.current_balance` — the wallet balance includes non-loan
 * obligations and adjustments. A test below verifies the exclusion at
 * the HTTP surface.
 *
 * =============================================================================
 * Cross-tenant vectors
 * =============================================================================
 *   - reconciliation on a cross-tenant loan id → 404
 *   - forged `X-Tenant-ID` header → 403
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createLoanPermission()` — not `createPermission()` — to avoid the
 * method-name collision with the protected helper on `Tests\TestCase`.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Feature
 * @version 1.0.0
 */
final class LoanReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $borrowerUser;
    private User $stranger;
    private User $userWithoutPermission;
    private Shareholder $borrower;
    private Shareholder $otherBorrower;
    private Wallet $wallet;
    private LoanProduct $product;
    private LoanProduct $otherProduct;
    private VersionedPolicy $policy;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->tenant = Tenant::create([
            'slug'      => 'main-tenant',
            'name_am'   => 'ዋና ቴናንት',
            'name_en'   => 'Main Tenant',
            'domain'    => 'main.maalearna.com.et',
            'is_active' => true,
        ]);

        $this->otherTenant = Tenant::create([
            'slug'      => 'other-tenant',
            'name_am'   => 'ሌላ ቴናንት',
            'name_en'   => 'Other Tenant',
            'domain'    => 'other.maalearna.com.et',
            'is_active' => true,
        ]);

        $this->admin       = $this->makeUserInTenant('admin');
        $this->borrowerUser = $this->makeUserInTenant('borroweruser');
        $this->stranger    = $this->makeUserInTenant('stranger');
        $this->userWithoutPermission = $this->makeUserInTenant('noperm');

        // ---------------------------------------------------------------------
        // Permissions.
        //
        //   admin        → loan.view (broad path)
        //   borrowerUser → loan.view_own (own-only path)
        //   stranger     → loan.view_own (own-only, no ownership match)
        // ---------------------------------------------------------------------
        $this->createLoanPermission('loan.view',       'View loans',       [$this->admin]);
        $this->createLoanPermission('loan.view_own',   'View own loans',   [$this->borrowerUser, $this->stranger]);

        foreach ([$this->admin, $this->borrowerUser, $this->stranger, $this->userWithoutPermission] as $user) {
            $user->load('permissions');
        }

        // ---------------------------------------------------------------------
        // Shareholders, wallets, products, policy.
        // ---------------------------------------------------------------------
        $this->borrower = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'user_id'          => $this->borrowerUser->id,
            'is_active'        => true,
        ]);

        $this->otherBorrower = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሳሙኤል',
            'father_name_am'   => 'ተስፋዬ',
            'is_active'        => true,
        ]);

        $this->wallet = Wallet::create([
            'tenant_id'       => $this->tenant->id,
            'shareholder_id'  => $this->borrower->id,
            'currency'        => 'ETB',
            'current_balance' => '0.0000',
            'is_active'       => true,
            'opened_at'       => now(),
        ]);

        $this->policy       = $this->makePolicy();
        $this->product      = $this->makeProduct($this->tenant, 'PROD-A', $this->policy);
        $this->otherProduct = $this->makeProduct($this->otherTenant, 'PROD-B', $this->policy);

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    public function test_reconciliation_requires_authentication(): void
    {
        $loan = $this->makeActiveLoan();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // Authorization — shared `view` ability
    // =========================================================================

    public function test_reconciliation_grants_broad_view_permission(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_reconciliation_grants_own_view_when_user_owns_loan(): void
    {
        // `borrowerUser` is linked to the borrower Shareholder via the
        // `user_id` FK. The policy's own-only path grants.
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->borrowerUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_reconciliation_denies_own_view_when_user_does_not_own_loan(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->stranger, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
    }

    public function test_reconciliation_denies_user_without_permission(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
    }

    // =========================================================================
    // Report shape
    // =========================================================================

    public function test_reconciliation_response_has_expected_envelope_shape(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'loan_id',
                'currency',
                'status',
                'reconciled_at',
                'contractual',
                'loan_projected',
                'wallet_linked',
                'wallet_payments',
                'per_installment',
                'divergence',
                'is_balanced',
                'provenance',
            ],
        ]);
    }

    public function test_reconciliation_report_carries_loan_id(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.loan_id', $loan->id);
    }

    public function test_reconciliation_report_carries_status_and_currency(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', LoanStatus::Active->value);
        $response->assertJsonPath('data.currency', 'ETB');
    }

    public function test_reconciliation_provenance_block_has_expected_shape(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'provenance' => [
                    'wallet_id',
                    'reconciler',
                    'reconciler_version',
                ],
            ],
        ]);
    }

    // =========================================================================
    // is_balanced — well-formed loan
    // =========================================================================

    public function test_reconciliation_is_balanced_for_well_formed_loan(): void
    {
        $loan = $this->makeActiveLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligation($loan, ObligationComponent::Principal, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_balanced', true);
    }

    public function test_reconciliation_divergence_total_is_zero_when_balanced(): void
    {
        $loan = $this->makeActiveLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligation($loan, ObligationComponent::Principal, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.divergence.total', '0.0000');
    }

    // =========================================================================
    // is_balanced — deliberately unbalanced loan
    // =========================================================================

    public function test_reconciliation_is_unbalanced_when_wallet_side_is_higher(): void
    {
        $loan = $this->makeActiveLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        // Wallet obligation of 11000 against a loan projection of 10000.
        $this->makeObligation($loan, ObligationComponent::Principal, '11000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_balanced', false);
        $response->assertJsonPath('data.divergence.total', '1000.0000');
    }

    public function test_reconciliation_is_unbalanced_when_wallet_side_is_lower(): void
    {
        $loan = $this->makeActiveLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligation($loan, ObligationComponent::Principal, '9000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_balanced', false);
        $response->assertJsonPath('data.divergence.total', '-1000.0000');
    }

    // =========================================================================
    // The C5 wallet-balance exclusion
    // =========================================================================

    public function test_reconciliation_ignores_unrelated_wallet_obligations(): void
    {
        // A non-loan obligation on the same wallet must not affect the
        // balance verdict.
        $loan = $this->makeActiveLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligation($loan, ObligationComponent::Principal, '10000.0000');

        Obligation::create([
            'wallet_id'          => $this->wallet->id,
            'component'          => ObligationComponent::Fee->value,
            'currency'           => $this->wallet->currency,
            'original_amount'    => '500.0000',
            'outstanding_amount' => '500.0000',
            'reference_type'     => 'Wallet',
            'reference_id'       => (string) $this->wallet->id,
            'occurred_at'        => now(),
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_balanced', true);
    }

    public function test_reconciliation_does_not_expose_current_balance(): void
    {
        // The C5 correction explicitly forbids comparing against
        // `wallets.current_balance`. The report's `wallet_linked`
        // section must not carry that key.
        $loan = $this->makeActiveLoan();
        $this->makeObligation($loan, ObligationComponent::Principal, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.wallet_linked.current_balance');
    }

    // =========================================================================
    // Pre-disbursement loan
    // =========================================================================

    public function test_reconciliation_of_approved_loan_is_balanced(): void
    {
        // A loan in `approved` state has no Wallet obligations; the
        // loan's projections are null (migration A.9 defaults), which
        // the reconciler treats as zero.
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_balanced', true);
    }

    public function test_reconciliation_of_approved_loan_has_no_wallet_linked_obligations(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet_linked.obligation_count', 0);
        $response->assertJsonPath('data.wallet_linked.outstanding_total', '0.0000');
    }

    // =========================================================================
    // Report sections
    // =========================================================================

    public function test_reconciliation_loan_projected_section_has_expected_shape(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'loan_projected' => [
                    'outstanding_principal',
                    'outstanding_interest',
                    'outstanding_penalty',
                    'settled_amount',
                    'outstanding_total',
                ],
            ],
        ]);
    }

    public function test_reconciliation_wallet_linked_section_has_expected_shape(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'wallet_linked' => [
                    'wallet_id',
                    'obligation_count',
                    'settled_obligation_count',
                    'outstanding_principal',
                    'outstanding_interest',
                    'outstanding_penalty',
                    'outstanding_total',
                ],
            ],
        ]);
    }

    public function test_reconciliation_divergence_section_has_expected_shape(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'divergence' => [
                    'principal',
                    'interest',
                    'penalty',
                    'total',
                ],
            ],
        ]);
    }

    public function test_reconciliation_contractual_section_has_expected_shape(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'contractual' => [
                    'total_expected_principal',
                    'total_expected_interest',
                    'total_expected_repayment',
                    'active_installment_count',
                    'settled_installment_count',
                    'overdue_installment_count',
                    'pending_or_partial_installment_count',
                    'superseded_installment_count',
                ],
            ],
        ]);
    }

    public function test_reconciliation_wallet_payments_section_has_expected_shape(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'wallet_payments' => [
                    'payment_count',
                    'total_received',
                    'total_allocated',
                    'total_unapplied',
                ],
            ],
        ]);
    }

    // =========================================================================
    // Payments aggregation
    // =========================================================================

    public function test_reconciliation_counts_loan_linked_payments(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeObligation($loan, ObligationComponent::Principal, '10000.0000');

        Payment::create([
            'wallet_id'        => $this->wallet->id,
            'currency'         => $this->wallet->currency,
            'amount'           => '500.0000',
            'unapplied_amount' => '500.0000',
            'reference_type'   => 'LoanRepayment',
            'reference_id'     => (string) $loan->id,
            'received_at'      => now(),
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.wallet_payments.payment_count', 1);
        $response->assertJsonPath('data.wallet_payments.total_received', '500.0000');
    }

    // =========================================================================
    // Per-installment breakdown
    // =========================================================================

    public function test_reconciliation_per_installment_is_a_list(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment($loan, number: 1);
        $this->makeInstallment($loan, number: 2);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.per_installment');
    }

    public function test_reconciliation_per_installment_entries_are_ordered_by_number(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment($loan, number: 3);
        $this->makeInstallment($loan, number: 1);
        $this->makeInstallment($loan, number: 2);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.per_installment.0.installment_number', 1);
        $response->assertJsonPath('data.per_installment.1.installment_number', 2);
        $response->assertJsonPath('data.per_installment.2.installment_number', 3);
    }

    // =========================================================================
    // Determinism
    // =========================================================================

    public function test_reconciliation_is_deterministic(): void
    {
        $loan = $this->makeActiveLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );
        $this->makeObligation($loan, ObligationComponent::Principal, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $first = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $second = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $first->assertStatus(200);
        $second->assertStatus(200);

        // The entire report is byte-for-byte identical, except the
        // `reconciled_at` timestamp (which varies by a fraction of a
        // second between requests). Compare the report minus that
        // field.
        $firstReport = $first->json('data');
        $secondReport = $second->json('data');

        unset($firstReport['reconciled_at'], $secondReport['reconciled_at']);

        $this->assertSame($firstReport, $secondReport);
    }

    // =========================================================================
    // Cross-tenant and non-existent
    // =========================================================================

    public function test_reconciliation_returns_404_for_nonexistent_loan(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => 999999]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_reconciliation_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeActiveLoan(
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $foreignLoan->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // Semantic leak check.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB borrower name must not leak into a rejected response.',
        );
    }

    public function test_tenant_header_cannot_be_forged_for_reconciliation(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->otherTenant->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function makeUserInTenant(string $role): User
    {
        $user = User::factory()->create([
            'email' => $role . '-' . uniqid() . '@example.test',
        ]);
        $user->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $user->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        return $user;
    }

    private function makePolicy(): VersionedPolicy
    {
        return VersionedPolicy::create([
            'policy_type' => 'loan_product_rules',
            'payload'     => [
                'interest' => ['annual_rate' => '0.12'],
                'term'     => [
                    'principal_grace_months' => 0,
                    'interest_grace_months'  => 0,
                ],
            ],
        ]);
    }

    private function makeProduct(
        Tenant $tenant,
        string $code,
        ?VersionedPolicy $policy = null,
    ): LoanProduct {
        $attributes = [
            'tenant_id'                     => $tenant->id,
            'code'                          => $code,
            'name_am'                       => 'የሙከራ ምርት',
            'name_en'                       => 'Test Product',
            'currency'                      => 'ETB',
            'min_principal'                 => '1000.0000',
            'max_principal'                 => '100000.0000',
            'min_term_months'               => 6,
            'max_term_months'               => 60,
            'default_installment_frequency' => 'monthly',
            'is_active'                     => true,
        ];

        if ($policy !== null) {
            $attributes['product_rules_policy_version_id'] = $policy->id;
        }

        return LoanProduct::create($attributes);
    }

    /**
     * Create an active loan with baseline history rows.
     */
    private function makeActiveLoan(
        ?Tenant $tenant = null,
        ?Shareholder $borrower = null,
        ?LoanProduct $product = null,
        string $outstandingPrincipal = '10000.0000',
        string $outstandingInterest = '0.0000',
        string $outstandingPenalty = '0.0000',
    ): Loan {
        $tenant   ??= $this->tenant;
        $borrower ??= $this->borrower;
        $product  ??= $this->product;

        $loan = new Loan();
        $loan->tenant_id                 = $tenant->id;
        $loan->borrower_shareholder_id   = $borrower->id;
        $loan->loan_product_id           = $product->id;
        $loan->principal_amount          = '10000.0000';
        $loan->term_months               = 12;
        $loan->status                    = LoanStatus::Active;
        $loan->application_date          = now()->subMonths(3);
        $loan->decision_date             = now()->subMonths(3);
        $loan->disbursement_date         = now()->subMonths(3);
        $loan->currency                  = 'ETB';
        $loan->settled_amount            = '0.0000';
        $loan->outstanding_principal     = $outstandingPrincipal;
        $loan->outstanding_interest      = $outstandingInterest;
        $loan->outstanding_penalty       = $outstandingPenalty;
        $loan->total_scheduled_interest  = '0.0000';
        $loan->total_scheduled_repayment = '10000.0000';
        $loan->monthly_installment_amount = '833.3333';
        $loan->maturity_date             = now()->addMonths(9);
        $loan->frozen_policy_version_id  = $product->product_rules_policy_version_id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);
        $this->writeHistory($loan, LoanStatus::Approved, LoanStatus::Disbursed);
        $this->writeHistory($loan, LoanStatus::Disbursed, LoanStatus::Active);

        return $loan;
    }

    private function makeApprovedLoan(): Loan
    {
        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $this->borrower->id;
        $loan->loan_product_id          = $this->product->id;
        $loan->principal_amount         = '10000.0000';
        $loan->term_months              = 12;
        $loan->status                   = LoanStatus::Approved;
        $loan->application_date         = now();
        $loan->decision_date            = now();
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->frozen_policy_version_id = $this->product->product_rules_policy_version_id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);

        return $loan;
    }

    private function makeInstallment(
        Loan $loan,
        int $number = 1,
    ): LoanInstallment {
        return LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => $number,
            'due_date'           => now()->addMonths($number)->toDateString(),
            'expected_principal' => '1000.0000',
            'expected_interest'  => '20.0000',
            'expected_total'     => '1020.0000',
        ]);
    }

    private function makeObligation(
        Loan $loan,
        ObligationComponent $component,
        string $amount,
    ): Obligation {
        return Obligation::create([
            'wallet_id'          => $this->wallet->id,
            'component'          => $component->value,
            'currency'           => $this->wallet->currency,
            'original_amount'    => $amount,
            'outstanding_amount' => $amount,
            'reference_type'     => 'Loan',
            'reference_id'       => (string) $loan->id,
            'occurred_at'        => now(),
        ]);
    }

    private function writeHistory(
        Loan $loan,
        ?LoanStatus $from,
        LoanStatus $to,
    ): void {
        LoanStatusHistory::create([
            'loan_id'           => $loan->id,
            'from_status'       => $from?->value,
            'to_status'         => $to->value,
            'transition_reason' => null,
            'actor_user_id'     => null,
            'occurred_at'       => now(),
            'created_at'        => now(),
        ]);
    }

    /**
     * Create a Loan permission and attach it to the given users.
     *
     * @param array<int, User> $recipients
     */
    private function createLoanPermission(string $slug, string $label, array $recipients = []): Permission
    {
        $permission = Permission::create([
            'slug'           => $slug,
            'name_am'        => null,
            'name_en'        => $label,
            'description_am' => null,
            'description_en' => $label,
            'category'       => 'loan',
            'scope'          => 'tenant',
            'tenant_id'      => null,
            'application_id' => null,
            'is_active'      => true,
            'is_system'      => true,
        ]);

        foreach ($recipients as $user) {
            $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
        }

        return $permission;
    }
}