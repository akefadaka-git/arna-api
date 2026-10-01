<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanApprovalTest
 *
 * Feature tests for the loan-decision HTTP surface:
 *
 *     POST /loans/{loan}/approve    approve   — approve a submitted loan
 *     POST /loans/{loan}/reject     reject    — reject a submitted loan
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
 * The feature tests do NOT assert on after-commit events. The
 * `LoanApproved` (A.39) and `LoanRejected` (A.40) events are dispatched
 * via the state machine's `DB::afterCommit()` path, but the unit suite
 * (A.94) already verifies the dispatch and the payload. This file's
 * focus is the HTTP contract: request shape, authorization layering,
 * validation, response envelope, and the state-machine guards reached
 * through the endpoint.
 *
 * `RefreshDatabase` is therefore the correct default per §2.13 and the
 * pattern followed by the Shareholder and Wallet feature suites.
 *
 * =============================================================================
 * Authorization layering
 * =============================================================================
 * Both endpoints require:
 *
 *   1. Authentication via `auth:sanctum`.
 *   2. Tenant membership via `tenant.membership`.
 *   3. The route-specific permission:
 *        approve → `loan.approve`
 *        reject  → `loan.reject`
 *
 *   The permission is enforced through the `LoanPolicy::approve()` /
 *   `LoanPolicy::reject()` abilities dispatched by the controller
 *   (A.77). The policy resolves the permission via
 *   `AuthorizationService::hasPermission()`.
 *
 * =============================================================================
 * The intermediate `under_review` step
 * =============================================================================
 * Per A.29, an approval or rejection performed on a `submitted` loan
 * produces TWO history rows (a silent `submitted → under_review` pickup
 * followed by the decision). The feature tests exercise both entry
 * states: a loan in `submitted` and a loan in `under_review`.
 *
 * =============================================================================
 * Cross-tenant vectors
 * =============================================================================
 *   - approve on a cross-tenant loan id → 404
 *   - reject on a cross-tenant loan id → 404
 *
 * Both produce the SAME 404 as a non-existent loan id, preserving the
 * tenant-isolation invariant (handoff §3).
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
 * @version 1.0.1
 */
final class LoanApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithoutPermission;
    private Shareholder $borrower;
    private Shareholder $otherBorrower;
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

        $this->admin = User::factory()->create([
            'email' => 'admin-' . uniqid() . '@example.test',
        ]);
        $this->admin->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->admin->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->userWithoutPermission = User::factory()->create([
            'email' => 'noperm-' . uniqid() . '@example.test',
        ]);
        $this->userWithoutPermission->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->userWithoutPermission->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        // ---------------------------------------------------------------------
        // The permissions the endpoints require.
        // ---------------------------------------------------------------------
        $this->createLoanPermission('loan.view',    'View loans');
        $this->createLoanPermission('loan.approve', 'Approve loans');
        $this->createLoanPermission('loan.reject',  'Reject loans');

        $this->admin->load('permissions');

        // ---------------------------------------------------------------------
        // Shareholders, products, and the frozen policy version.
        // ---------------------------------------------------------------------
        $this->borrower = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);

        $this->otherBorrower = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሳሙኤል',
            'father_name_am'   => 'ተስፋዬ',
            'is_active'        => true,
        ]);

        $this->policy       = $this->makePolicy();
        $this->product      = $this->makeProduct($this->tenant, 'PROD-A', $this->policy);
        $this->otherProduct = $this->makeProduct($this->otherTenant, 'PROD-B');

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // approve() — authentication
    // =========================================================================

    public function test_approve_requires_authentication(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]));

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // approve() — authorization
    // =========================================================================

    public function test_approve_requires_approve_permission(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);

        // The loan is unchanged.
        $this->assertSame(
            LoanStatus::Submitted,
            $loan->fresh()->status,
        );
    }

    // =========================================================================
    // approve() — happy path from submitted
    // =========================================================================

    public function test_approve_transitions_submitted_to_approved(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', LoanStatus::Approved->value);
        $response->assertJsonPath('data.id', $loan->id);

        $this->assertSame(LoanStatus::Approved, $loan->fresh()->status);
    }

    public function test_approve_records_decision_metadata(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]))
          ->assertStatus(200);

        $loan->refresh();

        $this->assertNotNull($loan->decision_date);
        $this->assertSame((int) $this->admin->id, (int) $loan->approved_by);
    }

    public function test_approve_freezes_policy_version(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]))
          ->assertStatus(200);

        $loan->refresh();
        $this->assertSame(
            (int) $this->policy->id,
            (int) $loan->frozen_policy_version_id,
        );
    }

    public function test_approve_from_submitted_writes_two_history_rows(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]))
          ->assertStatus(200);

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        // The fixture writes the initial-creation row and the
        // draft→submitted transition. The approval adds two more:
        // submitted→under_review and under_review→approved.
        $this->assertCount(4, $history);

        $pickupRow = $history[2];
        $decisionRow = $history[3];

        $this->assertSame(LoanStatus::Submitted, $pickupRow->from_status);
        $this->assertSame(LoanStatus::UnderReview, $pickupRow->to_status);

        $this->assertSame(LoanStatus::UnderReview, $decisionRow->from_status);
        $this->assertSame(LoanStatus::Approved, $decisionRow->to_status);
    }

    // =========================================================================
    // approve() — happy path from under_review
    // =========================================================================

    public function test_approve_from_under_review_writes_one_decision_row(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]))
          ->assertStatus(200);

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        // The fixture writes the initial-creation row, the
        // draft→submitted transition, and the submitted→under_review
        // transition. The approval adds one more.
        $this->assertCount(4, $history);

        $decisionRow = $history[3];
        $this->assertSame(LoanStatus::UnderReview, $decisionRow->from_status);
        $this->assertSame(LoanStatus::Approved, $decisionRow->to_status);
    }

    // =========================================================================
    // approve() — optional reason
    // =========================================================================

    public function test_approve_accepts_optional_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]), [
            'reason' => 'Approved by committee.',
        ])->assertStatus(200);

        $row = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::Approved->value)
            ->firstOrFail();

        $this->assertSame('Approved by committee.', $row->transition_reason);
    }

    public function test_approve_accepts_empty_body(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]))
          ->assertStatus(200);
    }

    // =========================================================================
    // approve() — state guard
    // =========================================================================

    public function test_approve_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]));

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_approve_rejects_already_approved_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Approved);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_approve_rejects_rejected_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Rejected);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    // =========================================================================
    // approve() — cross-tenant and non-existent
    // =========================================================================

    public function test_approve_returns_404_for_nonexistent_loan(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => 999999]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    public function test_approve_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeLoanInState(
            LoanStatus::Submitted,
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $foreignLoan->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The foreign loan is unchanged.
        $this->assertSame(
            LoanStatus::Submitted,
            $foreignLoan->fresh()->status,
        );

        // Semantic leak check.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB borrower name must not leak into a rejected response.',
        );
    }

    // =========================================================================
    // reject() — authentication
    // =========================================================================

    public function test_reject_requires_authentication(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => 'Insufficient documentation.',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // reject() — authorization
    // =========================================================================

    public function test_reject_requires_reject_permission(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => 'Insufficient documentation.',
        ]);

        $response->assertStatus(403);

        $this->assertSame(
            LoanStatus::Submitted,
            $loan->fresh()->status,
        );
    }

    // =========================================================================
    // reject() — happy path from submitted
    // =========================================================================

    public function test_reject_transitions_submitted_to_rejected(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => 'Insufficient documentation.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', LoanStatus::Rejected->value);

        $this->assertSame(LoanStatus::Rejected, $loan->fresh()->status);
    }

    public function test_reject_records_decision_date_only(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => 'Insufficient documentation.',
        ])->assertStatus(200);

        $loan->refresh();

        // Rejection writes the decision date but NOT approved_by.
        $this->assertNotNull($loan->decision_date);
        $this->assertNull($loan->approved_by);
        $this->assertNull($loan->frozen_policy_version_id);
    }

    public function test_reject_from_submitted_writes_two_history_rows(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => 'Insufficient documentation.',
        ])->assertStatus(200);

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $history);

        $pickupRow   = $history[2];
        $decisionRow = $history[3];

        $this->assertSame(LoanStatus::Submitted, $pickupRow->from_status);
        $this->assertSame(LoanStatus::UnderReview, $pickupRow->to_status);

        $this->assertSame(LoanStatus::UnderReview, $decisionRow->from_status);
        $this->assertSame(LoanStatus::Rejected, $decisionRow->to_status);
        $this->assertSame('Insufficient documentation.', $decisionRow->transition_reason);
        $this->assertSame((int) $this->admin->id, (int) $decisionRow->actor_user_id);
    }

    // =========================================================================
    // reject() — reason required
    // =========================================================================

    public function test_reject_rejects_missing_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_reject_rejects_empty_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_reject_rejects_whitespace_only_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => "   \t\n  ",
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_reject_rejects_overlong_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => str_repeat('a', 501),
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reason']]);
    }

    // =========================================================================
    // reject() — state guard
    // =========================================================================

    public function test_reject_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => 'Insufficient documentation.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_reject_rejects_already_rejected_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Rejected);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loan->id]), [
            'reason' => 'Double rejection.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    // =========================================================================
    // reject() — cross-tenant and non-existent
    // =========================================================================

    public function test_reject_returns_404_for_nonexistent_loan(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => 999999]), [
            'reason' => 'Insufficient documentation.',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_reject_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeLoanInState(
            LoanStatus::Submitted,
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.reject', ['loan' => $foreignLoan->id]), [
            'reason' => 'Insufficient documentation.',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The foreign loan is unchanged.
        $this->assertSame(
            LoanStatus::Submitted,
            $foreignLoan->fresh()->status,
        );
    }

    // =========================================================================
    // Response envelope
    // =========================================================================

    public function test_approve_response_has_expected_envelope_shape(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'tenant_id',
                'borrower_shareholder_id',
                'loan_product_id',
                'status',
                'principal_amount',
                'term_months',
            ],
        ]);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

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
     * Create a loan directly via the model with the baseline history
     * rows appropriate to the given state.
     */
    private function makeLoanInState(
        LoanStatus $status,
        ?Tenant $tenant = null,
        ?Shareholder $borrower = null,
        ?LoanProduct $product = null,
    ): Loan {
        $tenant   ??= $this->tenant;
        $borrower ??= $this->borrower;
        $product  ??= $this->product;

        $loan = new Loan();
        $loan->tenant_id               = $tenant->id;
        $loan->borrower_shareholder_id = $borrower->id;
        $loan->loan_product_id         = $product->id;
        $loan->principal_amount        = '5000.0000';
        $loan->term_months             = 12;
        $loan->status                  = $status;
        $loan->application_date        = now();
        $loan->settled_amount          = '0.0000';
        $loan->outstanding_penalty     = '0.0000';
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);

        if ($status !== LoanStatus::Draft) {
            $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        }

        if (
            $status !== LoanStatus::Draft
            && $status !== LoanStatus::Submitted
        ) {
            $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        }

        if (
            $status === LoanStatus::Approved
            || $status === LoanStatus::Rejected
            || $status === LoanStatus::Disbursed
            || $status === LoanStatus::Active
        ) {
            $this->writeHistory($loan, LoanStatus::UnderReview, $status);
        }

        return $loan;
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
     * Create a Loan permission and attach it to the admin user.
     *
     * Named `createLoanPermission()` — not `createPermission()` — to
     * avoid a method-name collision with the base `Tests\TestCase`.
     */
    private function createLoanPermission(string $slug, string $label): Permission
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

        $this->admin->permissions()->attach($permission->id, ['assigned_at' => now()]);

        return $permission;
    }
}