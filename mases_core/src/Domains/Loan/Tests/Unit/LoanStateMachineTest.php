<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanApproved;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanStateMachine;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanStateMachineTest
 *
 * Unit-level coverage for `LoanStateMachine` (A.26) — the sole writer of
 * `loans.status` and `loan_status_histories` rows.
 *
 * =============================================================================
 * File placement
 * =============================================================================
 * Per the platform-wide convention (see 2A.48, 2B.57–2B.60, and the
 * Shareholder and Wallet domain test suites), this file lives INSIDE the
 * Loan module at `mases_core/src/Domains/Loan/Tests/Unit/` and carries
 * the namespace `MaaleArna\MASES\Core\Domains\Loan\Tests\Unit`. The
 * top-level `tests/` tree at the repository root is reserved for
 * platform-wide suites (Phase 1's tenancy tests); domain suites live
 * with their domain.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — docblock correction (no code change).
 *
 * 1.0.2 — R-22 fixture FK fix. `makePersistedLoan()` previously wrote
 *         `borrower_shareholder_id = 1` and `loan_product_id = 1`
 *         directly, assuming a fixture-row with those ids existed.
 *         Under `DatabaseMigrations`, the schema is wiped between test
 *         files, so no `shareholders.id = 1` and no `loan_products.id = 1`
 *         existed and the insert failed the FK constraint. The fix
 *         creates a `Shareholder` and a `LoanProduct` in `setUp()` and
 *         references their ids.
 *
 * 1.0.3 — R-23 fixture FK fix. Two tests passed hardcoded actor ids
 *         (`42` and `99`) to `initialize()` / `transition()`. Both
 *         services write the actor id to `loan_status_histories.actor_user_id`,
 *         which carries an FK to `users.id`. Under `DatabaseMigrations`,
 *         no such user rows exist, so the insert failed the FK
 *         constraint. The fix creates a `User` fixture in `setUp()` and
 *         passes `$this->actor->id`; the assertions that compared
 *         against the literal ids become comparisons against
 *         `$this->actor->id`.
 *
 *         No behavior changes; no production code touched; no assertion
 *         meaning changed.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * The state machine registers its event dispatch via `DB::afterCommit()`.
 * Under `RefreshDatabase`, the framework's per-test transaction never
 * commits, so the after-commit callbacks never fire and `Event::fake()`
 * cannot observe the dispatch. `DatabaseMigrations` avoids the wrapping
 * transaction, allowing the state machine's caller-supplied transaction
 * to be the outermost one.
 *
 * =============================================================================
 * Event-assertion closures are non-static
 * =============================================================================
 * The closure in `test_transition_dispatches_after_commit_event` accesses
 * `$loan` (captured by `use`). That is sufficient to require a
 * non-static closure; the platform-wide convention (documented at 2A.49
 * and 2B.57) is that any event-assertion closure that reaches beyond its
 * own arguments MUST NOT be declared `static fn`. The convention exists
 * because a future revision of the closure may need `$this`.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * The tenant helper is `makeTenant()` — not `createTenant()` — to avoid
 * the signature collision with the protected method declared on
 * `Tests\TestCase`. The same convention is used by 2A.48, 2A.49, 2B.57,
 * 2B.58, 2B.59, 2B.60, and 2B.61.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle. The
 * Plesk shared-host CLI environment has a low memory ceiling, and PHPUnit
 * keeps every test's fixture graph alive until the next test's `setUp()`.
 * The explicit collection keeps the peak memory below the ceiling for a
 * suite with dozens of tests.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.3
 */
final class LoanStateMachineTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * The system-under-test.
     */
    private LoanStateMachine $stateMachine;

    /**
     * The tenant that owns every fixture loan.
     */
    private Tenant $tenant;

    /**
     * The borrower fixture for every loan row this suite creates.
     */
    private Shareholder $borrower;

    /**
     * The loan-product fixture for every loan row this suite creates.
     *
     * The state machine does not read the product; the row is created
     * solely to satisfy the `loans.loan_product_id` FK.
     */
    private LoanProduct $product;

    /**
     * The actor-user fixture for every transition this suite drives
     * with a non-null actor.
     *
     * The state machine does not read the user; the row is created
     * solely to satisfy the `loan_status_histories.actor_user_id` FK.
     */
    private User $actor;

    /**
     * Set up the test.
     *
     * See the class docblock for the memory-hygiene rationale.
     */
    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->stateMachine = app(LoanStateMachine::class);

        $this->tenant = $this->makeTenant('state-machine-tenant', 'የስቴት ማሽን ቴናንት');

        // The two FK targets the `loans` table requires. See the class
        // docblock §"Revision history (1.0.2)".
        $this->borrower = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);

        $this->product = LoanProduct::create([
            'tenant_id'                     => $this->tenant->id,
            'code'                          => 'PROD-STATE-MACHINE',
            'name_am'                       => 'የሙከራ ምርት',
            'name_en'                       => 'Test Product',
            'currency'                      => 'ETB',
            'min_principal'                 => '1000.0000',
            'max_principal'                 => '100000.0000',
            'min_term_months'               => 1,
            'max_term_months'               => 60,
            'default_installment_frequency' => 'monthly',
            'is_active'                     => true,
        ]);

        // The FK target for `loan_status_histories.actor_user_id`. See
        // the class docblock §"Revision history (1.0.3)".
        $this->actor = User::factory()->create([
            'email' => 'actor-' . uniqid() . '@example.test',
        ]);

        // Deterministic clock. Several tests assert on the `occurred_at`
        // / `created_at` values the state machine writes; pinning the
        // clock makes those assertions reproducible.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        gc_collect_cycles();
    }

    /**
     * Tear down the test.
     */
    protected function tearDown(): void
    {
        gc_collect_cycles();

        Carbon::setTestNow();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // initialize()
    // =========================================================================

    public function test_initialize_writes_initial_history_row(): void
    {
        $loan = $this->makePersistedLoan(LoanStatus::Draft);

        DB::transaction(function () use ($loan): void {
            $this->stateMachine->initialize($loan, actorUserId: null);
        });

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->get();

        $this->assertCount(1, $history);

        $row = $history->first();

        $this->assertNull($row->from_status);
        $this->assertSame(LoanStatus::Draft, $row->to_status);
        $this->assertNull($row->transition_reason);
        $this->assertNull($row->actor_user_id);
    }

    public function test_initialize_records_actor_when_supplied(): void
    {
        $loan = $this->makePersistedLoan(LoanStatus::Draft);

        DB::transaction(function () use ($loan): void {
            $this->stateMachine->initialize($loan, actorUserId: $this->actor->id);
        });

        $row = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame($this->actor->id, $row->actor_user_id);
    }

    public function test_initialize_requires_transaction(): void
    {
        $loan = $this->makePersistedLoan(LoanStatus::Draft);

        $this->expectException(LogicException::class);

        $this->stateMachine->initialize($loan);
    }

    public function test_initialize_rejects_unpersisted_loan(): void
    {
        $loan = new Loan();
        $loan->status = LoanStatus::Draft;

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->stateMachine->initialize($loan);
        });
    }

    // =========================================================================
    // transition() — transaction and persistence guards
    // =========================================================================

    public function test_transition_requires_transaction(): void
    {
        $loan = $this->makePersistedLoan(LoanStatus::Draft);

        $this->expectException(LogicException::class);

        $this->stateMachine->transition($loan, LoanStatus::Submitted);
    }

    public function test_transition_rejects_unpersisted_loan(): void
    {
        $loan = new Loan();
        $loan->status = LoanStatus::Draft;

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->stateMachine->transition($loan, LoanStatus::Submitted);
        });
    }

    // =========================================================================
    // transition() — structural matrix (legal transitions)
    // =========================================================================
    #[DataProvider('legalTransitionProvider')]
    public function test_transition_accepts_legal_transitions(
        LoanStatus $from,
        LoanStatus $to,
        ?string $reason,
    ): void {
        $loan = $this->makePersistedLoan($from);

        DB::transaction(function () use ($loan, $to, $reason): void {
            $this->stateMachine->transition(
                loan:       $loan,
                nextStatus: $to,
                reason:     $reason,
            );
        });

        $refreshed = $loan->fresh();
        $this->assertSame($to, $refreshed->status);
    }

    /**
     * @return array<string, array{0: LoanStatus, 1: LoanStatus, 2: ?string}>
     */
    public static function legalTransitionProvider(): array
    {
        return [
            'draft→submitted'          => [LoanStatus::Draft, LoanStatus::Submitted, null],
            'submitted→under_review'   => [LoanStatus::Submitted, LoanStatus::UnderReview, null],
            'under_review→approved'    => [LoanStatus::UnderReview, LoanStatus::Approved, null],
            'under_review→rejected'    => [LoanStatus::UnderReview, LoanStatus::Rejected, 'rejected'],
            'approved→disbursed'       => [LoanStatus::Approved, LoanStatus::Disbursed, null],
            'disbursed→active'         => [LoanStatus::Disbursed, LoanStatus::Active, null],
            'active→in_arrears'        => [LoanStatus::Active, LoanStatus::InArrears, null],
            'active→settled'           => [LoanStatus::Active, LoanStatus::Settled, null],
            'active→written_off'       => [LoanStatus::Active, LoanStatus::WrittenOff, 'write-off'],
            'in_arrears→restructured'  => [LoanStatus::InArrears, LoanStatus::Restructured, 'restructured'],
            'in_arrears→settled'       => [LoanStatus::InArrears, LoanStatus::Settled, null],
            'in_arrears→written_off'   => [LoanStatus::InArrears, LoanStatus::WrittenOff, 'write-off'],
            'restructured→active'      => [LoanStatus::Restructured, LoanStatus::Active, null],
            'restructured→settled'     => [LoanStatus::Restructured, LoanStatus::Settled, null],
            'restructured→written_off' => [LoanStatus::Restructured, LoanStatus::WrittenOff, 'write-off'],
            'settled→closed'           => [LoanStatus::Settled, LoanStatus::Closed, null],
        ];
    }

    // =========================================================================
    // transition() — structural matrix (illegal transitions)
    // =========================================================================
    #[DataProvider('illegalTransitionProvider')]
    public function test_transition_rejects_illegal_transitions(
        LoanStatus $from,
        LoanStatus $to,
    ): void {
        $loan = $this->makePersistedLoan($from);

        try {
            DB::transaction(function () use ($loan, $to): void {
                $this->stateMachine->transition(
                    loan:       $loan,
                    nextStatus: $to,
                );
            });

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    /**
     * @return array<string, array{0: LoanStatus, 1: LoanStatus}>
     */
    public static function illegalTransitionProvider(): array
    {
        return [
            // Self-transitions.
            'draft→draft'        => [LoanStatus::Draft, LoanStatus::Draft],
            'active→active'      => [LoanStatus::Active, LoanStatus::Active],
            'settled→settled'    => [LoanStatus::Settled, LoanStatus::Settled],

            // From final states.
            'rejected→active'    => [LoanStatus::Rejected, LoanStatus::Active],
            'rejected→approved'  => [LoanStatus::Rejected, LoanStatus::Approved],
            'written_off→active' => [LoanStatus::WrittenOff, LoanStatus::Active],
            'closed→active'      => [LoanStatus::Closed, LoanStatus::Active],

            // Skip-ahead transitions.
            'draft→approved'      => [LoanStatus::Draft, LoanStatus::Approved],
            'draft→disbursed'     => [LoanStatus::Draft, LoanStatus::Disbursed],
            'submitted→approved'  => [LoanStatus::Submitted, LoanStatus::Approved],
            'submitted→rejected'  => [LoanStatus::Submitted, LoanStatus::Rejected],
            'approved→active'     => [LoanStatus::Approved, LoanStatus::Active],
            'disbursed→settled'   => [LoanStatus::Disbursed, LoanStatus::Settled],
            'active→restructured' => [LoanStatus::Active, LoanStatus::Restructured],
            'in_arrears→active'   => [LoanStatus::InArrears, LoanStatus::Active],
            'settled→active'      => [LoanStatus::Settled, LoanStatus::Active],
        ];
    }

    // =========================================================================
    // transition() — state writes
    // =========================================================================

    public function test_transition_updates_status_column(): void
    {
        $loan = $this->makePersistedLoan(LoanStatus::Draft);

        DB::transaction(function () use ($loan): void {
            $this->stateMachine->transition($loan, LoanStatus::Submitted);
        });

        $this->assertSame(LoanStatus::Submitted, $loan->fresh()->status);
    }

    public function test_transition_writes_history_row(): void
    {
        $loan = $this->makePersistedLoan(LoanStatus::UnderReview);

        DB::transaction(function () use ($loan): void {
            $this->stateMachine->transition(
                loan:        $loan,
                nextStatus:  LoanStatus::Approved,
                reason:      'approved per committee',
                actorUserId: $this->actor->id,
            );
        });

        $row = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::Approved->value)
            ->firstOrFail();

        $this->assertSame(LoanStatus::UnderReview, $row->from_status);
        $this->assertSame(LoanStatus::Approved, $row->to_status);
        $this->assertSame('approved per committee', $row->transition_reason);
        $this->assertSame($this->actor->id, $row->actor_user_id);
        $this->assertNotNull($row->occurred_at);
        $this->assertNotNull($row->created_at);
    }

    public function test_transition_history_row_timestamps_match(): void
    {
        $loan = $this->makePersistedLoan(LoanStatus::Draft);

        DB::transaction(function () use ($loan): void {
            $this->stateMachine->transition($loan, LoanStatus::Submitted);
        });

        $row = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame(
            $row->occurred_at->toIso8601String(),
            $row->created_at->toIso8601String(),
        );
    }

    public function test_transition_returns_refreshed_loan(): void
    {
        $loan = $this->makePersistedLoan(LoanStatus::Draft);

        /** @var Loan $returned */
        $returned = DB::transaction(function () use ($loan): Loan {
            return $this->stateMachine->transition($loan, LoanStatus::Submitted);
        });

        $this->assertSame($loan->id, $returned->id);
        $this->assertSame(LoanStatus::Submitted, $returned->status);
    }

    // =========================================================================
    // transition() — reason-required enforcement
    // =========================================================================
    #[DataProvider('reasonRequiredTransitionProvider')]
    public function test_transition_enforces_required_reason(
        LoanStatus $from,
        LoanStatus $to,
    ): void {
        $loan = $this->makePersistedLoan($from);

        try {
            DB::transaction(function () use ($loan, $to): void {
                $this->stateMachine->transition(
                    loan:       $loan,
                    nextStatus: $to,
                    reason:     null,
                );
            });

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transition_reason', $e->errors());
        }
    }

    #[DataProvider('reasonRequiredTransitionProvider')]
    public function test_transition_rejects_whitespace_only_reason(
        LoanStatus $from,
        LoanStatus $to,
    ): void {
        $loan = $this->makePersistedLoan($from);

        try {
            DB::transaction(function () use ($loan, $to): void {
                $this->stateMachine->transition(
                    loan:       $loan,
                    nextStatus: $to,
                    reason:     '   ',
                );
            });

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transition_reason', $e->errors());
        }
    }

    /**
     * @return array<string, array{0: LoanStatus, 1: LoanStatus}>
     */
    public static function reasonRequiredTransitionProvider(): array
    {
        return [
            'under_review→rejected'    => [LoanStatus::UnderReview, LoanStatus::Rejected],
            'in_arrears→restructured'  => [LoanStatus::InArrears, LoanStatus::Restructured],
            'active→written_off'       => [LoanStatus::Active, LoanStatus::WrittenOff],
            'in_arrears→written_off'   => [LoanStatus::InArrears, LoanStatus::WrittenOff],
            'restructured→written_off' => [LoanStatus::Restructured, LoanStatus::WrittenOff],
        ];
    }

    public function test_transition_accepts_optional_reason(): void
    {
        $loan = $this->makePersistedLoan(LoanStatus::UnderReview);

        DB::transaction(function () use ($loan): void {
            $this->stateMachine->transition(
                loan:       $loan,
                nextStatus: LoanStatus::Approved,
                reason:     'optional context',
            );
        });

        $row = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame('optional context', $row->transition_reason);
    }

    // =========================================================================
    // transition() — after-commit event dispatch
    // =========================================================================

    public function test_transition_dispatches_after_commit_event(): void
    {
        Event::fake([LoanApproved::class]);

        $loan = $this->makePersistedLoan(LoanStatus::UnderReview);

        DB::transaction(function () use ($loan): void {
            $event = new LoanApproved($loan);

            $this->stateMachine->transition(
                loan:             $loan,
                nextStatus:       LoanStatus::Approved,
                afterCommitEvent: $event,
            );
        });

        // Closure captures `$loan` — MUST NOT be declared `static fn`.
        // See the class docblock §"Event-assertion closures are non-static".
        Event::assertDispatched(
            LoanApproved::class,
            function (LoanApproved $event) use ($loan): bool {
                return $event->loanId() === (int) $loan->id;
            },
        );
    }

    public function test_transition_does_not_dispatch_event_on_rollback(): void
    {
        Event::fake([LoanApproved::class]);

        $loan = $this->makePersistedLoan(LoanStatus::UnderReview);

        try {
            DB::transaction(function () use ($loan): void {
                $event = new LoanApproved($loan);

                $this->stateMachine->transition(
                    loan:             $loan,
                    nextStatus:       LoanStatus::Approved,
                    afterCommitEvent: $event,
                );

                throw new RuntimeException('simulated downstream failure');
            });
        } catch (RuntimeException) {
            // Expected; the transaction rolled back.
        }

        Event::assertNotDispatched(LoanApproved::class);

        $this->assertSame(
            LoanStatus::UnderReview,
            $loan->fresh()->status,
        );
    }

    public function test_transition_respects_emit_events_gate(): void
    {
        config()->set('loan.audit.emit_events', false);

        Event::fake([LoanApproved::class]);

        $loan = $this->makePersistedLoan(LoanStatus::UnderReview);

        DB::transaction(function () use ($loan): void {
            $event = new LoanApproved($loan);

            $this->stateMachine->transition(
                loan:             $loan,
                nextStatus:       LoanStatus::Approved,
                afterCommitEvent: $event,
            );
        });

        Event::assertNotDispatched(LoanApproved::class);

        $this->assertSame(
            LoanStatus::Approved,
            $loan->fresh()->status,
        );
    }

    public function test_transition_without_event_is_permitted(): void
    {
        Event::fake();

        $loan = $this->makePersistedLoan(LoanStatus::Draft);

        DB::transaction(function () use ($loan): void {
            $this->stateMachine->transition($loan, LoanStatus::Submitted);
        });

        $this->assertSame(
            LoanStatus::Submitted,
            $loan->fresh()->status,
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Create a persisted loan in the given status without going through
     * the state machine.
     *
     * Direct insertion is the correct fixture strategy for a unit test of
     * the state machine: the machine is the system-under-test, so a
     * fixture that used it to prepare its own inputs would couple the
     * setup to the behavior being tested.
     *
     * `status` is not in the `Loan` model's `$fillable` array (per A.18),
     * so mass assignment is not an option; the fixture uses the model's
     * attribute-assignment + save path.
     *
     * The `borrower_shareholder_id` and `loan_product_id` FKs reference
     * the fixtures created in `setUp()`. See the class docblock
     * §"Revision history (1.0.2)".
     */
    private function makePersistedLoan(LoanStatus $status): Loan
    {
        $loan = new Loan();
        $loan->tenant_id               = $this->tenant->id;
        $loan->borrower_shareholder_id = $this->borrower->id;
        $loan->loan_product_id         = $this->product->id;
        $loan->principal_amount        = '10000.0000';
        $loan->term_months             = 12;
        $loan->status                  = $status;
        $loan->application_date        = Carbon::now();
        $loan->settled_amount          = '0.0000';
        $loan->outstanding_penalty     = '0.0000';
        $loan->save();

        return $loan;
    }

    /**
     * Create a tenant.
     *
     * Named `makeTenant()` — not `createTenant()` — to avoid a signature
     * collision with the protected method declared on `Tests\TestCase`.
     * See the class docblock §"Fixture helper naming".
     */
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
}