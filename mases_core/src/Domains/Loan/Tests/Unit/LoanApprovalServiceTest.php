<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use LogicException;
use RuntimeException;
use Tests\Concerns\LoanDatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanApproved;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRejected;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanApprovalService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanApprovalServiceTest
 *
 * Unit-level coverage for `LoanApprovalService` (A.29) — the DECISION
 * phase of a loan's lifecycle: `approve()` and `reject()`.
 *
 * =============================================================================
 * File placement
 * =============================================================================
 * Per the platform-wide convention (see 2A.48, 2B.57–2B.61 and the
 * Shareholder and Wallet domain test suites), this file lives INSIDE the
 * Loan module at `mases_core/src/Domains/Loan/Tests/Unit/` and carries
 * the namespace `MaaleArna\MASES\Core\Domains\Loan\Tests\Unit`.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — R-22 fixture FK fix. `makeLoanInState()` previously wrote
 *         `borrower_shareholder_id = 1` directly. Under
 *         `DatabaseMigrations`, the schema is wiped between test files,
 *         so no `shareholders.id = 1` existed and the insert failed the
 *         FK constraint. The fix creates a `Shareholder` in `setUp()`
 *         and references its id.
 *
 * 1.0.2 — R-23 fixture FK fix. Every test that reached the approve /
 *         reject write paths passed a hardcoded actor id (`7` or `42`)
 *         to the service. The service writes it to
 *         `loan_status_histories.actor_user_id` (every transition) and
 *         to `loans.approved_by` (on approve). Both columns carry an FK
 *         to `users.id`. Under `DatabaseMigrations`, no such user rows
 *         exist, so every such write failed the FK constraint. The fix
 *         creates a `User` fixture in `setUp()` and passes
 *         `$this->actor->id`; the assertions that compared against the
 *         literal ids become comparisons against `$this->actor->id`.
 *
 * 1.0.3 — Two residual test-side defects corrected. These were latent
 *         in v1.0.0–v1.0.2, masked by the earlier FK failures: with the
 *         FK failures fixed, the history assertions and the
 *         missing-product setup became reachable and revealed the
 *         mismatches below.
 *
 *         (a) `makeLoanInState()` history-row inflation. The helper
 *             wrote the full status-progression chain into
 *             `loan_status_histories` (`null → Draft`, then
 *             `Draft → Submitted` when status ≠ Draft, then
 *             `Submitted → UnderReview` when status ∉ {Draft,
 *             Submitted}). The tests, however, assume a SINGLE
 *             baseline row and expect the service to produce the
 *             remaining rows. For `UnderReview` the fixture wrote 3
 *             rows where the test expected 1; for `Submitted` it
 *             wrote 2 where the test expected 1. The fix reduces
 *             `makeLoanInState()` to write exactly the initial-creation
 *             row (`null → Draft`) for every target status. The state
 *             machine and the approval service read the loan's
 *             `status` COLUMN, not the history, so the row-count
 *             reduction does not affect what the services do — it
 *             affects only what the history assertions observe.
 *
 *         (b) `test_approve_rejects_loan_with_missing_product` FK
 *             violation. The test tried to set `loan_product_id =
 *             999999` on a persisted loan to simulate a dangling FK
 *             reference. `loans.loan_product_id` has an FK to
 *             `loan_products.id`, and SQLite rejects the update. The
 *             test now runs that single update with
 *             `PRAGMA foreign_keys = OFF` and immediately restores the
 *             pragma after the update, so the update succeeds and the
 *             rest of the test (and every subsequent test) runs with
 *             FK enforcement intact.
 *
 *         No production code touched; no test method renamed; no
 *         assertion meaning changed beyond the two corrections above.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * Both `approve()` and `reject()` dispatch their events
 * (`LoanApproved`, `LoanRejected`) via the state machine's
 * `DB::afterCommit()` path. Under `RefreshDatabase`, the framework's
 * per-test transaction never commits, so the after-commit callbacks
 * never fire and `Event::fake()` cannot observe the dispatch.
 * `DatabaseMigrations` avoids the wrapping transaction; the service's
 * own `DB::transaction(...)` becomes the outermost one, and its commit
 * triggers the after-commit callbacks.
 *
 * =============================================================================
 * The intermediate `under_review` step
 * =============================================================================
 * Per A.29's class docblock, both `approve()` and `reject()` accept a
 * loan in either `submitted` or `under_review` state. When the loan is
 * still in `submitted`, the service performs a silent
 * `submitted → under_review` transition before the decision transition.
 * The tests below cover both entry states:
 *
 *   - Starting from `submitted`: the resulting history log has TWO rows
 *     added by the service (the review pickup and the decision) on top
 *     of the single fixture baseline row.
 *   - Starting from `under_review`: the resulting history log has ONE
 *     row added by the service (the decision) on top of the single
 *     fixture baseline row.
 *
 * See the class docblock §"Revision history (1.0.3)" item (a) for the
 * fixture change that aligns the baseline with this expectation.
 *
 * =============================================================================
 * The decision metadata written by the service
 * =============================================================================
 * On APPROVE: `approved_by`, `decision_date`, and
 * `frozen_policy_version_id` are all written.
 *
 * On REJECT: only `decision_date` is written; `approved_by` and
 * `frozen_policy_version_id` remain null. The rejecting actor is
 * recorded on the corresponding `loan_status_histories.actor_user_id`
 * row, not on the loan row.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.3
 */
final class LoanApprovalServiceTest extends TestCase
{
    use LoanDatabaseMigrations;

    private LoanApprovalService $service;
    private Tenant $tenant;
    private Shareholder $borrower;
    private LoanProduct $product;
    private VersionedPolicy $policy;

    /**
     * The actor-user fixture for every approve / reject call this suite
     * makes with a non-null actor.
     *
     * The service does not read the user; the row is created solely to
     * satisfy the `loan_status_histories.actor_user_id` and
     * `loans.approved_by` FKs.
     */
    private User $actor;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanApprovalService::class);

        $this->tenant  = $this->makeTenant('approval-tenant', 'የፍቃድ ቴናንት');
        $this->policy  = $this->makePolicy();
        $this->product = $this->makeProduct($this->tenant, $this->policy);

        // The borrower fixture for every loan row this suite creates.
        // See the class docblock §"Revision history (1.0.1)".
        $this->borrower = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);

        // The actor-user fixture. See the class docblock §"Revision
        // history (1.0.2)".
        $this->actor = User::factory()->create([
            'email' => 'actor-' . uniqid() . '@example.test',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-01 09:00:00'));

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        Carbon::setTestNow();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // approve() — happy path from under_review
    // =========================================================================

    public function test_approve_transitions_under_review_to_approved(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $result = $this->service->approve($loan, actorUserId: $this->actor->id);

        $this->assertSame(LoanStatus::Approved, $result->status);
    }

    public function test_approve_freezes_policy_version(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->approve($loan, actorUserId: $this->actor->id);

        $loan->refresh();
        $this->assertSame(
            (int) $this->policy->id,
            (int) $loan->frozen_policy_version_id,
        );
    }

    public function test_approve_writes_approved_by(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->approve($loan, actorUserId: $this->actor->id);

        $loan->refresh();
        $this->assertSame($this->actor->id, (int) $loan->approved_by);
    }

    public function test_approve_writes_decision_date(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->approve($loan, actorUserId: $this->actor->id);

        $loan->refresh();
        $this->assertNotNull($loan->decision_date);
        $this->assertSame(
            '2026-07-01',
            $loan->decision_date->toDateString(),
        );
    }

    public function test_approve_writes_one_history_row_from_under_review(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        // The fixture writes exactly one baseline row: the
        // initial-creation transition (`null → Draft`). See the class
        // docblock §"Revision history (1.0.3)" item (a).
        $this->assertSame(
            1,
            LoanStatusHistory::query()->where('loan_id', $loan->id)->count(),
        );

        $this->service->approve($loan, actorUserId: $this->actor->id);

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        // Two rows total: initial-creation baseline + the decision.
        $this->assertCount(2, $history);

        $transition = $history[1];
        $this->assertSame(LoanStatus::UnderReview, $transition->from_status);
        $this->assertSame(LoanStatus::Approved, $transition->to_status);
        $this->assertSame($this->actor->id, (int) $transition->actor_user_id);
    }

    // =========================================================================
    // approve() — happy path from submitted
    // =========================================================================

    public function test_approve_from_submitted_produces_two_history_rows(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        $this->service->approve($loan, actorUserId: $this->actor->id);

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        // Three rows total: initial-creation baseline (from fixture) +
        // silent review pickup + decision (both from the service).
        $this->assertCount(3, $history);

        // Row 0: initial-creation (from_status null → draft).
        $this->assertNull($history[0]->from_status);
        $this->assertSame(LoanStatus::Draft, $history[0]->to_status);

        // Row 1: silent review pickup (submitted → under_review).
        $this->assertSame(LoanStatus::Submitted, $history[1]->from_status);
        $this->assertSame(LoanStatus::UnderReview, $history[1]->to_status);

        // Row 2: the decision (under_review → approved).
        $this->assertSame(LoanStatus::UnderReview, $history[2]->from_status);
        $this->assertSame(LoanStatus::Approved, $history[2]->to_status);
        $this->assertSame($this->actor->id, (int) $history[2]->actor_user_id);
    }

    public function test_approve_from_submitted_ends_in_approved_state(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        $result = $this->service->approve($loan, actorUserId: $this->actor->id);

        $this->assertSame(LoanStatus::Approved, $result->status);
        $this->assertSame(LoanStatus::Approved, $loan->fresh()->status);
    }

    // =========================================================================
    // approve() — optional reason
    // =========================================================================

    public function test_approve_accepts_optional_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->approve(
            loan:        $loan,
            reason:      'Approved by committee.',
            actorUserId: $this->actor->id,
        );

        $transition = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::Approved->value)
            ->firstOrFail();

        $this->assertSame('Approved by committee.', $transition->transition_reason);
    }

    public function test_approve_accepts_null_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->approve(
            loan:        $loan,
            reason:      null,
            actorUserId: $this->actor->id,
        );

        $transition = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::Approved->value)
            ->firstOrFail();

        $this->assertNull($transition->transition_reason);
    }

    // =========================================================================
    // approve() — state guard
    // =========================================================================

    public function test_approve_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);

        try {
            $this->service->approve($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_approve_rejects_already_approved_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Approved);

        try {
            $this->service->approve($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_approve_rejects_rejected_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Rejected);

        try {
            $this->service->approve($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_approve_rejects_disbursed_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Disbursed);

        try {
            $this->service->approve($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    // =========================================================================
    // approve() — policy version requirement
    // =========================================================================

    public function test_approve_rejects_product_without_policy_version(): void
    {
        // A product whose `product_rules_policy_version_id` is null
        // cannot originate an approvable loan.
        $product = $this->makeProduct($this->tenant, policy: null);
        $loan    = $this->makeLoanInState(LoanStatus::UnderReview, product: $product);

        try {
            $this->service->approve($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('loan_product_id', $e->errors());
        }
    }

    public function test_approve_rejects_loan_with_missing_product(): void
    {
        // A loan whose product_id points at a non-existent product is a
        // data-integrity defect. The service surfaces it as a
        // ModelNotFoundException from the `loadMissing('product')`
        // resolution... or a LogicException from the explicit null
        // check, depending on the exact code path.
        //
        // ------------------------------------------------------------------
        // FK enforcement — read this before editing.
        // ------------------------------------------------------------------
        // `loans.loan_product_id` carries an FK to `loan_products.id`.
        // SQLite rejects any INSERT/UPDATE that would create a dangling
        // reference. To construct the defect this test exercises, the
        // single UPDATE that points the loan at a non-existent product
        // runs with `PRAGMA foreign_keys = OFF`; the pragma is restored
        // to `ON` immediately after, so every other statement in this
        // test (and every subsequent test) runs with the FK intact.
        //
        // See the class docblock §"Revision history (1.0.3)" item (b).
        // ------------------------------------------------------------------
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            $loan->loan_product_id = 999999;
            $loan->save();
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $this->expectException(LogicException::class);

        $this->service->approve($loan, actorUserId: $this->actor->id);
    }

    // =========================================================================
    // approve() — after-commit event
    // =========================================================================

    public function test_approve_dispatches_loan_approved_event(): void
    {
        Event::fake([LoanApproved::class]);

        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->approve($loan, actorUserId: $this->actor->id);

        // Closure captures `$loan` — MUST NOT be declared `static fn`.
        Event::assertDispatched(
            LoanApproved::class,
            function (LoanApproved $event) use ($loan): bool {
                return $event->loanId() === (int) $loan->id;
            },
        );
    }

    public function test_approve_event_carries_post_transition_status(): void
    {
        Event::fake([LoanApproved::class]);

        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->approve($loan, actorUserId: $this->actor->id);

        // The service refreshes the same instance the event holds
        // after the state machine transitions. By dispatch time, the
        // event's carried loan reflects the post-transition state.
        Event::assertDispatched(
            LoanApproved::class,
            function (LoanApproved $event): bool {
                return $event->loan->status === LoanStatus::Approved;
            },
        );
    }

    public function test_approve_respects_emit_events_gate(): void
    {
        config()->set('loan.audit.emit_events', false);

        Event::fake([LoanApproved::class]);

        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->approve($loan, actorUserId: $this->actor->id);

        Event::assertNotDispatched(LoanApproved::class);

        // The transition itself commits regardless.
        $this->assertSame(LoanStatus::Approved, $loan->fresh()->status);
    }

    public function test_approve_does_not_dispatch_event_on_rollback(): void
    {
        Event::fake([LoanApproved::class]);

        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        try {
            DB::transaction(function () use ($loan): void {
                $this->service->approve($loan, actorUserId: $this->actor->id);

                throw new RuntimeException('simulated downstream failure');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        Event::assertNotDispatched(LoanApproved::class);

        $this->assertSame(LoanStatus::UnderReview, $loan->fresh()->status);
    }

    // =========================================================================
    // reject() — happy path from under_review
    // =========================================================================

    public function test_reject_transitions_under_review_to_rejected(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $result = $this->service->reject(
            loan:        $loan,
            reason:      'Insufficient documentation.',
            actorUserId: $this->actor->id,
        );

        $this->assertSame(LoanStatus::Rejected, $result->status);
    }

    public function test_reject_writes_decision_date_only(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->reject(
            loan:        $loan,
            reason:      'Insufficient documentation.',
            actorUserId: $this->actor->id,
        );

        $loan->refresh();

        // Rejection writes the decision_date.
        $this->assertNotNull($loan->decision_date);

        // But not approved_by or frozen_policy_version_id.
        $this->assertNull($loan->approved_by);
        $this->assertNull($loan->frozen_policy_version_id);
    }

    public function test_reject_writes_history_row_with_actor_and_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->reject(
            loan:        $loan,
            reason:      'Insufficient documentation.',
            actorUserId: $this->actor->id,
        );

        $transition = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::Rejected->value)
            ->firstOrFail();

        $this->assertSame(LoanStatus::UnderReview, $transition->from_status);
        $this->assertSame(LoanStatus::Rejected, $transition->to_status);
        $this->assertSame('Insufficient documentation.', $transition->transition_reason);
        $this->assertSame($this->actor->id, (int) $transition->actor_user_id);
    }

    // =========================================================================
    // reject() — happy path from submitted
    // =========================================================================

    public function test_reject_from_submitted_produces_two_history_rows(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        $this->service->reject(
            loan:        $loan,
            reason:      'Insufficient documentation.',
            actorUserId: $this->actor->id,
        );

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        // Three rows total: initial-creation baseline (from fixture) +
        // silent review pickup + decision (both from the service).
        $this->assertCount(3, $history);

        $this->assertSame(LoanStatus::Submitted, $history[1]->from_status);
        $this->assertSame(LoanStatus::UnderReview, $history[1]->to_status);

        $this->assertSame(LoanStatus::UnderReview, $history[2]->from_status);
        $this->assertSame(LoanStatus::Rejected, $history[2]->to_status);
    }

    // =========================================================================
    // reject() — reason requirement (pre-transaction check)
    // =========================================================================

    public function test_reject_rejects_empty_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        try {
            $this->service->reject(
                loan:        $loan,
                reason:      '',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transition_reason', $e->errors());
        }
    }

    public function test_reject_rejects_whitespace_only_reason(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        try {
            $this->service->reject(
                loan:        $loan,
                reason:      "   \t\n  ",
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transition_reason', $e->errors());
        }
    }

    public function test_reject_reason_check_fires_before_transaction(): void
    {
        // The pre-transaction reason check means a whitespace-only
        // reason rejects BEFORE any lock is acquired. The loan row is
        // unchanged.
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        try {
            $this->service->reject(
                loan:        $loan,
                reason:      '',
                actorUserId: $this->actor->id,
            );
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(LoanStatus::UnderReview, $loan->fresh()->status);
        $this->assertNull($loan->fresh()->decision_date);
    }

    // =========================================================================
    // reject() — state guard
    // =========================================================================

    public function test_reject_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);

        try {
            $this->service->reject(
                loan:        $loan,
                reason:      'Valid reason.',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_reject_rejects_already_rejected_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Rejected);

        try {
            $this->service->reject(
                loan:        $loan,
                reason:      'Double rejection.',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    // =========================================================================
    // reject() — after-commit event
    // =========================================================================

    public function test_reject_dispatches_loan_rejected_event(): void
    {
        Event::fake([LoanRejected::class]);

        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->reject(
            loan:        $loan,
            reason:      'Insufficient documentation.',
            actorUserId: $this->actor->id,
        );

        // Closure captures `$loan` — MUST NOT be declared `static fn`.
        Event::assertDispatched(
            LoanRejected::class,
            function (LoanRejected $event) use ($loan): bool {
                return $event->loanId() === (int) $loan->id
                    && $event->reason() === 'Insufficient documentation.';
            },
        );
    }

    public function test_reject_event_carries_post_transition_status(): void
    {
        Event::fake([LoanRejected::class]);

        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->reject(
            loan:        $loan,
            reason:      'Insufficient documentation.',
            actorUserId: $this->actor->id,
        );

        Event::assertDispatched(
            LoanRejected::class,
            function (LoanRejected $event): bool {
                return $event->loan->status === LoanStatus::Rejected;
            },
        );
    }

    public function test_reject_respects_emit_events_gate(): void
    {
        config()->set('loan.audit.emit_events', false);

        Event::fake([LoanRejected::class]);

        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $this->service->reject(
            loan:        $loan,
            reason:      'Insufficient documentation.',
            actorUserId: $this->actor->id,
        );

        Event::assertNotDispatched(LoanRejected::class);

        $this->assertSame(LoanStatus::Rejected, $loan->fresh()->status);
    }

    // =========================================================================
    // Cross-tenant safety
    // =========================================================================

    public function test_approve_ignores_callers_tenant_when_operating_on_loan(): void
    {
        // The service operates on the loan's own tenant. This test
        // documents that no cross-tenant routing happens inside the
        // service; the caller's controller is responsible for
        // resolving the loan through the tenant-scoped repository
        // (A.55).
        $loan = $this->makeLoanInState(LoanStatus::UnderReview);

        $result = $this->service->approve($loan, actorUserId: $this->actor->id);

        $this->assertSame(LoanStatus::Approved, $result->status);
        $this->assertSame((int) $this->tenant->id, (int) $result->tenant_id);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function makeTenant(string $slug, string $nameAm): Tenant
    {
        return Tenant::create([
            'slug'      => $slug,
            'name_am'   => $nameAm,
            'name_en'   => ucfirst(str_replace('-', ' ', $slug)),
            'domain'    => $slug . '.example.test',
            'is_active' => true,
        ]);
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
        ?VersionedPolicy $policy = null,
    ): LoanProduct {
        $attributes = [
            'tenant_id'                     => $tenant->id,
            'code'                          => 'PROD-' . uniqid(),
            'name_am'                       => 'የሙከራ ምርት',
            'name_en'                       => 'Test Product',
            'currency'                      => 'ETB',
            'min_principal'                 => '1000.0000',
            'max_principal'                 => '100000.0000',
            'min_term_months'               => 1,
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
     * Build a loan in the given lifecycle state without going through
     * the state machine.
     *
     * The fixture writes the loan row directly and appends exactly ONE
     * `loan_status_histories` row — the initial-creation transition
     * (`null → Draft`) — as the baseline that the tests' history
     * assertions compare against. The state machine and the approval
     * service read the loan's `status` COLUMN, not the history, so the
     * single-baseline row does not affect what those services do; it
     * affects only the row counts the tests observe.
     *
     * An earlier version of this fixture wrote the full progression
     * chain (`null → Draft`, `Draft → Submitted`, `Submitted →
     * UnderReview`). The tests were always written against a single
     * baseline row, so the extra rows surfaced as count mismatches the
     * moment the earlier FK failures were removed. See the class
     * docblock §"Revision history (1.0.3)" item (a).
     *
     * The `borrower_shareholder_id` FK references the fixture created
     * in `setUp()`. See the class docblock §"Revision history (1.0.1)".
     */
    private function makeLoanInState(
        LoanStatus $status,
        ?LoanProduct $product = null,
    ): Loan {
        $product ??= $this->product;

        $loan = new Loan();
        $loan->tenant_id               = $this->tenant->id;
        $loan->borrower_shareholder_id = $this->borrower->id;
        $loan->loan_product_id         = $product->id;
        $loan->principal_amount        = '10000.0000';
        $loan->term_months             = 12;
        $loan->status                  = $status;
        $loan->application_date        = Carbon::now();
        $loan->settled_amount          = '0.0000';
        $loan->outstanding_penalty     = '0.0000';
        $loan->save();

        // Exactly one baseline history row: the initial-creation
        // transition. See the method docblock for the rationale.
        LoanStatusHistory::create([
            'loan_id'           => $loan->id,
            'from_status'       => null,
            'to_status'         => LoanStatus::Draft->value,
            'transition_reason' => null,
            'actor_user_id'     => null,
            'occurred_at'       => Carbon::now(),
            'created_at'        => Carbon::now(),
        ]);

        return $loan;
    }
}