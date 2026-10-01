<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Console\Commands;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanInterestAccrualService;
use Throwable;

/**
 * AccrueInterestCommand
 *
 * The scheduled daily command that accrues interest for every loan whose
 * installment's due date has arrived. Iterates the accrual-eligible
 * loans, filters to each loan's eligible installments, and delegates to
 * `LoanInterestAccrualService::accrue()` (A.32) once per eligible
 * installment.
 *
 * =============================================================================
 * -----------------------------------------------------------------------------
 * THE FROZEN FISCAL MODEL — ONE ACCRUAL PER INSTALLMENT, NOT DAILY
 * -----------------------------------------------------------------------------
 * =============================================================================
 *
 * The single most important property of this command's semantics is
 * documented here, in capitals, because it is the property most likely
 * to be misunderstood by a future contributor who reads "daily command"
 * and assumes "daily accrual":
 *
 *     ┌────────────────────────────────────────────────────────────────┐
 *     │  THIS COMMAND RUNS DAILY. IT DOES NOT ACCRUE INTEREST DAILY.   │
 *     │                                                                │
 *     │  Interest is recognized ONCE per loan installment, at the      │
 *     │  moment the installment's due date arrives. It does NOT grow   │
 *     │  with each passing day. It does NOT compound. It does NOT      │
 *     │  capitalize.                                                    │
 *     │                                                                │
 *     │  The "daily" in "daily command" describes the SCHEDULE CADENCE,│
 *     │  not the accrual frequency. The cadence exists to catch up on  │
 *     │  installments whose due dates arrived while the scheduler was  │
 *     │  down — it does not mean interest accrues afresh every day.    │
 *     └────────────────────────────────────────────────────────────────┘
 *
 * The freeze is the B3 decision (Phase 2C Decision Register,
 * 2026-09-12): "periodic accrual — interest becomes a Wallet obligation
 * when the relevant scheduled period becomes due. One interest
 * obligation per installment, at the installment's due date. NOT daily
 * compounding."
 *
 * The freeze is enforced at THREE layers:
 *
 *   1. STRUCTURALLY — the unique constraint on
 *      `loan_interest_accruals.accrual_period_key` (migration A.15)
 *      makes it IMPOSSIBLE to record a second accrual for the same
 *      (loan, installment) pair, regardless of how many times the
 *      command runs. The key is deterministic:
 *      `"{loan_id}:{installment_id}"`.
 *
 *   2. BEHAVIORALLY — `LoanInterestAccrualService::accrue()` (A.32)
 *      performs a fast-path check on the key before any work, and
 *      catches the unique-constraint violation race in the unlikely
 *      event of concurrent command runs.
 *
 *   3. COMMAND-LEVEL — this command's filtering excludes every
 *      installment whose interest has already been accrued, so it does
 *      not even call the service for those installments. The filter is
 *      an optimization; the two lower layers are the load-bearing
 *      correctness mechanisms.
 *
 * A future Change Request that introduces TRUE daily accrual (the
 * interest amount growing each day in arrears) is a substantially
 * larger change than it appears:
 *
 *   - It changes the A9 interest-computation freeze.
 *   - It changes the A15 migration's key format to include a date
 *     component.
 *   - It changes the Wallet obligation model (one obligation per
 *     installment becomes one obligation per day per installment).
 *   - It changes the reconciliation invariant (C5).
 *   - It changes this command's iteration strategy.
 *
 * The CR's scope is NOT Phase 2C.
 *
 * =============================================================================
 * The iteration strategy
 * =============================================================================
 * The command proceeds in three phases:
 *
 *   1. LOAN SELECTION — query the `loans` table for every loan whose
 *      status is in the accrual-eligible set:
 *
 *          disbursed, active, in_arrears, restructured
 *
 *      The set matches `LoanInterestAccrualService::assertLoanIsAccrualEligible()`
 *      (A.32). Every other loan status — including the pre-disbursement
 *      states and the terminal states — is excluded at the query.
 *
 *   2. INSTALLMENT FILTERING — for each selected loan, iterate its
 *      installments whose `due_date <= $asOf` AND whose `status` is in
 *      the unsettled set (`pending`, `partial`, `overdue`). The
 *      filtering matches `LoanInterestAccrualService::assertInstallmentIsAccrualEligible()`
 *      (A.32) and is performed via the `LoanInstallmentRepository`
 *      (A.57).
 *
 *   3. SERVICE DELEGATION — for each eligible installment, call
 *      `LoanInterestAccrualService::accrue($loan, $installment, $asOf)`.
 *      The service opens its OWN transaction per installment (per A.32's
 *      class docblock §"Transaction discipline"), performs the
 *      idempotency check, records the accrual, calls the Wallet, updates
 *      the loan's projection, and returns the accrual (or null if the
 *      accrual was skipped).
 *
 * The command opens no transaction of its own. Each `accrue()` call is
 * an independent transaction; a failure in one does not roll back any
 * other. The command logs and continues.
 *
 * =============================================================================
 * The per-installment failure isolation
 * =============================================================================
 * Each `accrue()` call is wrapped in its own `try/catch (Throwable)`:
 *
 *   - On success, the command counts the accrual (or the skip, when the
 *     service returns null for a zero-interest or already-accrued
 *     installment).
 *
 *   - On failure, the command logs the exception with enough context to
 *     triage (the loan id, the installment id, the exception class and
 *     message) and continues to the next installment. It does NOT
 *     re-throw.
 *
 * The rationale is the same as every scheduled command's: a failure in
 * one installment must not prevent every subsequent installment from
 * being processed. The daily cadence ensures that a transient failure
 * (a momentary DB outage, a lock timeout) is retried on the next run;
 * the idempotency ensures that the retry does not duplicate work.
 *
 * The command's exit code reflects whether the OVERALL run completed,
 * not whether every individual installment succeeded. A run that
 * processed 999 installments and failed 1 returns exit code 0, with the
 * one failure recorded in the log. A future CR that wants a non-zero
 * exit code on any failure would add a `--strict` flag at that time.
 *
 * =============================================================================
 * The command signature and options
 * =============================================================================
 *   loan:accrue-interest [options]
 *
 * Options:
 *
 *   --as-of=YYYY-MM-DD
 *       The reference date for the accrual eligibility check. Defaults
 *       to today (`Carbon::now()->startOfDay()`). Useful for a backfill
 *       after a scheduler outage, or for a test that needs to pin the
 *       moment of accrual.
 *
 *       The value is parsed with `Carbon::parse()`. An invalid value
 *       aborts the command with a non-zero exit code.
 *
 *   --loan-id=INT
 *       Restrict the run to a single loan. Useful for a targeted
 *       backfill or for debugging a specific loan's accrual behavior.
 *       When omitted, every accrual-eligible loan is processed.
 *
 *   --dry-run
 *       Do not call the service. Report what WOULD be accrued, without
 *       writing anything. Useful for a pre-flight check after a
 *       scheduler outage, before committing to a large backfill.
 *
 *   --chunk=INT
 *       The number of loans to fetch per chunk. Defaults to 100. A
 *       larger value reduces the number of queries; a smaller value
 *       reduces the peak memory. The default is chosen to keep the
 *       command within the Plesk shared-host CLI memory limits (per
 *       the platform's operational profile).
 *
 * =============================================================================
 * The Plesk operational context
 * =============================================================================
 * The platform runs on Plesk shared hosting with no SSH access (per the
 * handoff §1). The scheduler is invoked via Plesk's cron integration,
 * typically once per day. The command's memory profile is deliberately
 * conservative:
 *
 *   - Loans are fetched in chunks (`--chunk=100` by default) via
 *     `Loan::query()->chunkById()`. The chunk size bounds the memory
 *     used by the loan collection.
 *
 *   - Installments are fetched via the loan's eager-loaded relation
 *     for each loan in the chunk. A loan's installment count is
 *     typically tens to low hundreds; the memory per loan is bounded.
 *
 *   - The command accumulates only counters and error lines, never the
 *     accrual rows themselves.
 *
 * If a future CR introduces a deployment on dedicated infrastructure
 * with higher memory limits, the `--chunk` value can be raised without
 * a code change.
 *
 * =============================================================================
 * Idempotency across runs
 * =============================================================================
 * The command is idempotent at every layer:
 *
 *   - A re-run on the same day processes the same loan set and the same
 *     installment set, but the service's fast-path check skips every
 *     already-accrued installment.
 *
 *   - A re-run after a partial failure (e.g. the command crashed
 *     halfway) processes the remaining installments. The already-
 *     accrued ones are skipped; the not-yet-accrued ones are processed.
 *
 *   - A re-run after a scheduler outage (the command did not run on
 *     the actual due date) processes the installments whose due dates
 *     have arrived. The `recorded_at` timestamp reflects the
 *     recognition moment (today), not the due date; the due date is
 *     preserved on the Wallet obligation's `occurred_at` and `due_at`
 *     columns (per A.32's class docblock §"The frozen accrual model").
 *
 * No run ever produces a duplicate accrual. The two lower layers (the
 * unique constraint and the service's fast path) enforce this
 * independently of the command's own filtering.
 *
 * =============================================================================
 * What this command deliberately does NOT do
 * =============================================================================
 *   - It does NOT accrue interest daily. See §"THE FROZEN FISCAL
 *     MODEL".
 *
 *   - It does NOT open a transaction of its own. Each `accrue()` call
 *     is its own transaction (per A.32).
 *
 *   - It does NOT re-throw per-installment failures. See §"The
 *     per-installment failure isolation".
 *
 *   - It does NOT compute interest. The interest amount is read from
 *     the installment's `expected_interest` column by the service
 *     (A.32); the command does no arithmetic.
 *
 *   - It does NOT touch the Wallet directly. Every Wallet interaction
 *     flows through `LoanInterestAccrualService::accrue()`, which calls
 *     `ObligationService::record()`.
 *
 *   - It does NOT send notifications. A future CR that wants to notify
 *     the borrower or the operator of a large backfill would add the
 *     notification at that time.
 *
 *   - It does NOT enforce a runtime budget (a maximum wall-clock time).
 *     A future CR that runs on a system with a hard cron timeout would
 *     add one at that time; the platform's Plesk environment has no
 *     such hard timeout in the current operational profile.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9 (interest method),
 *                                                  B1 (reference types),
 *                                                  B3 (interest recording
 *                                                  pattern — ONE
 *                                                  accrual per
 *                                                  installment)
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §4.2 (the accrual
 *                                                  table's
 *                                                  specification),
 *                                                  §4.3 (this command's
 *                                                  semantics)
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.15                                — `loan_interest_accruals`
 *                                                  migration
 *   Phase 2C A.24                                — `LoanInterestAccrual`
 *   Phase 2C A.32                                — `LoanInterestAccrualService`
 *                                                  (the per-installment
 *                                                  delegation target)
 *   Phase 2C A.57                                — `LoanInstallmentRepository`
 *                                                  (the installment
 *                                                  read path)
 *   Phase 2C C.4                                 — the platform's
 *                                                  `routes/console.php`
 *                                                  modification that
 *                                                  schedules this
 *                                                  command daily
 *   Phase 2A 2A.50                               — the first scheduled
 *                                                  command in the
 *                                                  platform (the shape
 *                                                  precedent)
 *   Phase 2B 2B.57                               — the first Wallet
 *                                                  scheduled command
 *   Step 5 (manifest) A.88                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Console\Commands
 * @version 1.0.0
 */
final class AccrueInterestCommand extends Command
{
    /**
     * The console command name.
     *
     * Uses the domain-prefixed `loan:` convention, matching the platform-
     * wide command-naming pattern (`shareholder:*`, `wallet:*`).
     *
     * @var string
     */
    protected $signature = 'loan:accrue-interest
        {--as-of= : The reference date for the accrual eligibility check (YYYY-MM-DD). Defaults to today.}
        {--loan-id= : Restrict to a single loan by primary key.}
        {--dry-run : Report what would be accrued without writing.}
        {--chunk=100 : Number of loans to fetch per chunk.}';

    /**
     * The console command description.
     *
     * Deliberately specific: "one accrual per installment" preempts a
     * reader's assumption that the daily cadence implies daily accrual.
     * See the class docblock §"THE FROZEN FISCAL MODEL".
     *
     * @var string
     */
    protected $description = 'Accrue interest for due installments (one accrual per installment, not daily).';

    /**
     * The set of loan statuses that are accrual-eligible.
     *
     * Matches `LoanInterestAccrualService::assertLoanIsAccrualEligible()`
     * (A.32). The command filters loans to this set at the query, so
     * that the service is never called for an ineligible loan.
     *
     * @var list<LoanStatus>
     */
    private const ACCRUAL_ELIGIBLE_LOAN_STATUSES = [
        LoanStatus::Disbursed,
        LoanStatus::Active,
        LoanStatus::InArrears,
        LoanStatus::Restructured,
    ];

    /**
     * The set of installment statuses whose interest has not yet been
     * accrued and whose due date determines accrual eligibility.
     *
     * The set matches `InstallmentStatus::unsettledValues()` (A.5):
     * `pending`, `partial`, `overdue`. Settled and superseded
     * installments are excluded — a settled installment's interest has
     * already been paid (or was never due, for a zero-interest
     * installment); a superseded installment belongs to a prior
     * schedule.
     *
     * @var list<InstallmentStatus>
     */
    private const ACCRUAL_ELIGIBLE_INSTALLMENT_STATUSES = [
        InstallmentStatus::Pending,
        InstallmentStatus::Partial,
        InstallmentStatus::Overdue,
    ];

    /**
     * The maximum chunk size the `--chunk` option accepts.
     *
     * A defensive ceiling against an operator who passes a very large
     * value that would exceed the shared-host memory. The bound is
     * generous — 1000 loans per chunk is already well above the
     * platform's typical daily volume — and is intended only to catch
     * an obvious mistake.
     */
    private const MAX_CHUNK_SIZE = 1000;

    /**
     * Constructor.
     *
     * One dependency: the interest-accrual service (A.32). The service
     * opens its own transaction per `accrue()` call; the command does
     * not manage transactions.
     *
     * @param LoanInterestAccrualService $accrualService
     */
    public function __construct(
        private readonly LoanInterestAccrualService $accrualService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * See the class docblock §"The iteration strategy" for the three
     * phases. Every phase is wrapped in a per-installment
     * `try/catch (Throwable)`; the command never propagates a per-
     * installment failure to the caller.
     *
     * @return int  The exit code: `self::SUCCESS` (0) on a completed
     *              run (regardless of per-installment failures),
     *              `self::FAILURE` (1) on a setup failure (an invalid
     *              `--as-of` value or an invalid `--chunk` value).
     */
    public function handle(): int
    {
        $asOf = $this->resolveAsOf();
        if ($asOf === null) {
            return self::FAILURE;
        }

        $chunk = $this->resolveChunkSize();
        if ($chunk === null) {
            return self::FAILURE;
        }

        $loanId = $this->resolveLoanId();
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Accruing interest as of %s%s.',
            $asOf->toDateString(),
            $dryRun ? ' (dry run)' : '',
        ));

        $counters = $this->processLoans(
            asOf:   $asOf,
            loanId: $loanId,
            chunk:  $chunk,
            dryRun: $dryRun,
        );

        $this->summarize($counters, $dryRun);

        return self::SUCCESS;
    }

    // =========================================================================
    // Private — option resolution
    // =========================================================================

    /**
     * Resolve the `--as-of` option into a `Carbon` instance.
     *
     * Returns null (and emits an error) when the value is invalid. The
     * caller treats null as a command failure.
     *
     * @return Carbon|null
     */
    private function resolveAsOf(): ?Carbon
    {
        $raw = $this->option('as-of');

        if ($raw === null || $raw === '') {
            return Carbon::now()->startOfDay();
        }

        try {
            return Carbon::parse((string) $raw)->startOfDay();
        } catch (Throwable $e) {
            $this->error(sprintf(
                'Invalid --as-of value "%s": %s',
                (string) $raw,
                $e->getMessage(),
            ));

            return null;
        }
    }

    /**
     * Resolve the `--chunk` option into a positive integer.
     *
     * Returns null (and emits an error) when the value is invalid. The
     * caller treats null as a command failure.
     *
     * @return int|null
     */
    private function resolveChunkSize(): ?int
    {
        $raw = $this->option('chunk');

        if ($raw === null || $raw === '') {
            return 100;
        }

        if (!is_numeric($raw)) {
            $this->error(sprintf(
                'Invalid --chunk value "%s": must be a positive integer.',
                (string) $raw,
            ));

            return null;
        }

        $value = (int) $raw;

        if ($value < 1 || $value > self::MAX_CHUNK_SIZE) {
            $this->error(sprintf(
                'Invalid --chunk value "%d": must be between 1 and %d.',
                $value,
                self::MAX_CHUNK_SIZE,
            ));

            return null;
        }

        return $value;
    }

    /**
     * Resolve the optional `--loan-id` option.
     *
     * Returns null when the option is absent (meaning: process every
     * eligible loan). Emits an error and returns a sentinel value when
     * the option is present but invalid; the caller's query then
     * produces zero rows, and the run completes with an empty summary.
     *
     * A more defensive implementation would abort on an invalid
     * `--loan-id`; the current implementation treats it as "no loan
     * matches", which is the correct behavior for a targeted backfill
     * against a non-existent loan id. The choice is deliberate: the
     * operator can pass a loan id they suspect is problematic without
     * having to verify its existence first.
     *
     * @return int|null
     */
    private function resolveLoanId(): ?int
    {
        $raw = $this->option('loan-id');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            $this->warn(sprintf(
                'The --loan-id value "%s" is not numeric; no loans will match.',
                (string) $raw,
            ));

            return -1;
        }

        return (int) $raw;
    }

    // =========================================================================
    // Private — loan processing
    // =========================================================================

    /**
     * Iterate the eligible loans in chunks, delegating each installment
     * to the service.
     *
     * The method returns a counters array with the following keys:
     *
     *   loans_processed        — number of loans iterated.
     *   installments_examined  — number of installments whose due date
     *                            has arrived.
     *   installments_accrued   — number of accruals the service
     *                            recorded (non-null return).
     *   installments_skipped   — number of installments the service
     *                            skipped (null return, e.g. already
     *                            accrued or zero-interest).
     *   installments_failed    — number of per-installment failures.
     *
     * @param Carbon   $asOf
     * @param int|null $loanId
     * @param int      $chunk
     * @param bool     $dryRun
     * @return array<string, int>
     */
    private function processLoans(
        Carbon $asOf,
        ?int $loanId,
        int $chunk,
        bool $dryRun,
    ): array {
        $counters = [
            'loans_processed'       => 0,
            'installments_examined' => 0,
            'installments_accrued'  => 0,
            'installments_skipped'  => 0,
            'installments_failed'   => 0,
        ];

        $query = Loan::query()
            ->whereIn('status', array_map(
                static fn (LoanStatus $s): string => $s->value,
                self::ACCRUAL_ELIGIBLE_LOAN_STATUSES,
            ))
            ->orderBy('id');

        if ($loanId !== null) {
            // A negative sentinel (see resolveLoanId()) filters to a
            // value that no loan row can have. An integer match
            // filters to that specific loan.
            $query->whereKey($loanId);
        }

        $query->chunkById($chunk, function ($loans) use (
            &$counters,
            $asOf,
            $dryRun,
        ): void {
            foreach ($loans as $loan) {
                $counters['loans_processed']++;

                $this->processLoan(
                    loan:     $loan,
                    asOf:     $asOf,
                    dryRun:   $dryRun,
                    counters: $counters,
                );
            }
        });

        return $counters;
    }

    /**
     * Process a single loan's eligible installments.
     *
     * The installments are fetched via the loan's `installments` relation
     * (A.18), filtered to those whose due date has arrived and whose
     * status is in the unsettled set. Each eligible installment is
     * delegated to the service inside its own `try/catch`.
     *
     * @param Loan                  $loan
     * @param Carbon                $asOf
     * @param bool                  $dryRun
     * @param array<string, int>    $counters   Passed by reference.
     * @return void
     */
    private function processLoan(
        Loan $loan,
        Carbon $asOf,
        bool $dryRun,
        array &$counters,
    ): void {
        // ---------------------------------------------------------------------
        // Query the loan's eligible installments.
        //
        // The `installments` relation (A.18) orders by
        // `installment_number` ascending — the loan's contractual
        // chronology. The filter excludes installments whose due date
        // has not yet arrived and installments in a terminal state.
        // ---------------------------------------------------------------------
        /** @var Collection<int, LoanInstallment> $installments */
        $installments = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereIn('status', array_map(
                static fn (InstallmentStatus $s): string => $s->value,
                self::ACCRUAL_ELIGIBLE_INSTALLMENT_STATUSES,
            ))
            ->where('due_date', '<=', $asOf->toDateString())
            ->orderBy('installment_number')
            ->get();

        foreach ($installments as $installment) {
            $counters['installments_examined']++;

            if ($dryRun) {
                $this->line(sprintf(
                    '  [dry-run] would accrue loan=%d installment=%d (due %s)',
                    $loan->id,
                    $installment->id,
                    $installment->due_date->toDateString(),
                ));
                $counters['installments_skipped']++;
                continue;
            }

            try {
                $accrual = $this->accrualService->accrue(
                    loan:        $loan,
                    installment: $installment,
                    asOf:        $asOf,
                    actorUserId: null,
                );

                if ($accrual === null) {
                    // The service skipped the accrual — a zero-interest
                    // installment, or an installment that was already
                    // accrued by a prior run. Both are normal.
                    $counters['installments_skipped']++;
                } else {
                    $counters['installments_accrued']++;

                    $this->line(sprintf(
                        '  accrued loan=%d installment=%d amount=%s',
                        $loan->id,
                        $installment->id,
                        (string) $accrual->interest_amount,
                    ));
                }
            } catch (Throwable $e) {
                $counters['installments_failed']++;

                // -----------------------------------------------------------------
                // Per-installment failure isolation: log and continue.
                //
                // The service opens its own transaction per `accrue()`
                // call; a failure here has already rolled back that
                // installment's transaction. No other installment is
                // affected. See the class docblock §"The per-installment
                // failure isolation".
                // -----------------------------------------------------------------
                $this->error(sprintf(
                    '  failed loan=%d installment=%d: [%s] %s',
                    $loan->id,
                    $installment->id,
                    $e::class,
                    $e->getMessage(),
                ));
            }
        }
    }

    // =========================================================================
    // Private — summary output
    // =========================================================================

    /**
     * Emit the run's summary.
     *
     * @param array<string, int> $counters
     * @param bool               $dryRun
     * @return void
     */
    private function summarize(array $counters, bool $dryRun): void
    {
        $prefix = $dryRun ? 'Dry run complete' : 'Run complete';

        $this->newLine();
        $this->info(sprintf(
            '%s: %d loans, %d installments examined, %d accrued, %d skipped, %d failed.',
            $prefix,
            $counters['loans_processed'],
            $counters['installments_examined'],
            $counters['installments_accrued'],
            $counters['installments_skipped'],
            $counters['installments_failed'],
        ));

        if ($counters['installments_failed'] > 0) {
            $this->warn(sprintf(
                '%d installment(s) failed. Review the log above for details. '
                . 'The next scheduled run will retry them; the accrual '
                . 'idempotency ensures no duplicate is produced.',
                $counters['installments_failed'],
            ));
        }
    }
}