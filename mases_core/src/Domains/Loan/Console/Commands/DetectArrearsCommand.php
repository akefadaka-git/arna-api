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
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanArrearsService;
use MaaleArna\MASES\Core\Domains\Loan\Support\LoanPolicyKeys;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use Throwable;

/**
 * DetectArrearsCommand
 *
 * The scheduled daily command that detects overdue installments and
 * records the corresponding penalty obligation for each. Iterates the
 * arrears-eligible loans, filters to each loan's eligible installments
 * whose grace threshold has been crossed without full settlement, and
 * delegates to `LoanArrearsService::detect()` (A.33) once per eligible
 * installment.
 *
 * =============================================================================
 * -----------------------------------------------------------------------------
 * THE FROZEN FISCAL MODEL — ONE PENALTY PER INSTALLMENT, NOT DAILY
 * -----------------------------------------------------------------------------
 * =============================================================================
 *
 * The single most important property of this command's semantics is
 * documented here, in capitals, because it is the property most likely
 * to be misunderstood by a future contributor who reads "daily command"
 * and assumes "daily penalty":
 *
 *     ┌────────────────────────────────────────────────────────────────┐
 *     │  THIS COMMAND RUNS DAILY. IT DOES NOT ACCRUE PENALTIES DAILY.  │
 *     │                                                                │
 *     │  A penalty is recognized ONCE per overdue installment, at the  │
 *     │  moment the installment's grace threshold is crossed. It does  │
 *     │  NOT grow with each passing day. It does NOT compound. It      │
 *     │  does NOT capitalize into principal.                           │
 *     │                                                                │
 *     │  The "daily" in "daily command" describes the SCHEDULE CADENCE,│
 *     │  not the penalty frequency. The cadence exists to catch up on  │
 *     │  installments whose grace thresholds were crossed while the    │
 *     │  scheduler was down — it does not mean a fresh penalty accrues │
 *     │  every day.                                                    │
 *     └────────────────────────────────────────────────────────────────┘
 *
 * The freeze is the D133 decision (Phase 2C Decision Register,
 * 2026-09-12): "Form: penalty / late-charge obligation (NOT ordinary
 * contractual interest). Basis: the overdue installment amount. Start:
 * after the approved grace period. No compounding; no capitalization
 * into principal. One penalty per overdue installment, ever (enforced by
 * schema unique key)."
 *
 * The freeze is enforced at THREE layers:
 *
 *   1. STRUCTURALLY — the unique constraint on
 *      `loan_penalty_accruals.accrual_period_key` (migration A.16)
 *      makes it IMPOSSIBLE to record a second penalty for the same
 *      (loan, installment) pair, regardless of how many times the
 *      command runs. The key is deterministic:
 *      `"{loan_id}:{installment_id}"`.
 *
 *   2. BEHAVIORALLY — `LoanArrearsService::detect()` (A.33) performs a
 *      fast-path check on the key before any work, and catches the
 *      unique-constraint violation race in the unlikely event of
 *      concurrent command runs.
 *
 *   3. COMMAND-LEVEL — this command's filtering excludes every
 *      installment whose penalty has already been accrued, so it does
 *      not even call the service for those installments. The filter is
 *      an optimization; the two lower layers are the load-bearing
 *      correctness mechanisms.
 *
 * A future Change Request that introduces TRUE daily penalty accrual
 * (the penalty amount growing each day in arrears) is a substantially
 * larger change than it appears:
 *
 *   - It changes the D133 freeze.
 *   - It changes the A16 migration's key format to include a date
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
 * The command proceeds in four phases:
 *
 *   1. LOAN SELECTION — query the `loans` table for every loan whose
 *      status is in the arrears-eligible set:
 *
 *          active, in_arrears
 *
 *      The set matches `LoanArrearsService::assertLoanIsArrearsEligible()`
 *      (A.33). Every other loan status — including the pre-disbursement
 *      states, `disbursed`, `restructured`, and the terminal states —
 *      is excluded at the query. (`disbursed` and `restructured` are
 *      transient states that settle into `active` in the same
 *      transaction that produced them; the daily command never observes
 *      them in practice.)
 *
 *   2. POLICY RESOLUTION — for each selected loan, read the frozen
 *      `loan_product_rules` policy (via the loan's `policyVersion`
 *      relation, A.18) and extract:
 *
 *          arrears.enabled     — when false, the loan is SKIPPED
 *                                entirely (no installment of a loan
 *                                whose product disables arrears is
 *                                processed).
 *          arrears.grace_days  — the number of days after an
 *                                installment's due date before the
 *                                penalty applies.
 *
 *      The policy is read by the command so that the FILTER is
 *      authoritative: the command never calls the service for an
 *      installment that is not yet past its grace threshold, and never
 *      calls the service for a loan whose product disables arrears.
 *      The service re-reads the policy for defence (A.33).
 *
 *   3. INSTALLMENT FILTERING — for each policy-enabled loan, iterate
 *      its installments whose:
 *        - `status` is in the penalizable set (`pending`, `partial`),
 *        - `due_date + grace_days < asOf` (the grace threshold has
 *          been crossed),
 *        - `remaining_total > 0` (the installment is not fully settled
 *          despite its status; a defensive check, since `pending` and
 *          `partial` are by definition not settled — but the filter
 *          guards against a future schema change that decouples the
 *          status from the settled_amount).
 *
 *   4. SERVICE DELEGATION — for each eligible installment, call
 *      `LoanArrearsService::detect($loan, $installment, $asOf)`.
 *      The service opens its OWN transaction per installment (per
 *      A.33's class docblock §"Transaction discipline"), performs the
 *      idempotency check, records the penalty, calls the Wallet,
 *      updates the loan's projection, transitions the loan to
 *      `in_arrears` on the FIRST overdue installment, and returns the
 *      accrual (or null if the accrual was skipped).
 *
 * The command opens no transaction of its own. Each `detect()` call is
 * an independent transaction; a failure in one does not roll back any
 * other. The command logs and continues.
 *
 * =============================================================================
 * The per-installment failure isolation
 * =============================================================================
 * Each `detect()` call is wrapped in its own `try/catch (Throwable)`:
 *
 *   - On success, the command counts the penalty (or the skip, when the
 *     service returns null for an already-penalized installment or a
 *     zero-rate product).
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
 *   loan:detect-arrears [options]
 *
 * Options:
 *
 *   --as-of=YYYY-MM-DD
 *       The reference date for the arrears eligibility check. Defaults
 *       to today (`Carbon::now()->startOfDay()`). Useful for a backfill
 *       after a scheduler outage, or for a test that needs to pin the
 *       moment of detection.
 *
 *       The value is parsed with `Carbon::parse()`. An invalid value
 *       aborts the command with a non-zero exit code.
 *
 *   --loan-id=INT
 *       Restrict the run to a single loan. Useful for a targeted
 *       backfill or for debugging a specific loan's arrears behavior.
 *       When omitted, every arrears-eligible loan is processed.
 *
 *   --dry-run
 *       Do not call the service. Report what WOULD be penalized, without
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
 *   - Installments are fetched via a scoped query per loan. A loan's
 *     installment count is typically tens to low hundreds; the memory
 *     per loan is bounded.
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
 *     already-penalized installment.
 *
 *   - A re-run after a partial failure (e.g. the command crashed
 *     halfway) processes the remaining installments. The already-
 *     penalized ones are skipped; the not-yet-penalized ones are
 *     processed.
 *
 *   - A re-run after a scheduler outage (the command did not run on
 *     the actual grace-crossing day) processes the installments whose
 *     grace thresholds have been crossed. The `recorded_at` timestamp
 *     reflects the recognition moment (today), not the threshold date.
 *
 * No run ever produces a duplicate penalty. The two lower layers (the
 * unique constraint and the service's fast path) enforce this
 * independently of the command's own filtering.
 *
 * =============================================================================
 * The once-per-loan `in_arrears` transition
 * =============================================================================
 * On the FIRST overdue installment of a loan, `LoanArrearsService::detect()`
 * (A.33) performs the `active → in_arrears` state transition in the same
 * transaction as the penalty recording, and fires `LoanEnteredArrears`
 * (A.44) after commit. On subsequent overdue installments, the loan is
 * already `in_arrears`; no transition occurs, and only
 * `LoanPenaltyRecorded` (A.45) is dispatched.
 *
 * The command does not distinguish between the two cases; the service
 * handles the distinction internally. A listener registered for
 * `LoanEnteredArrears` receives at most one event per loan across the
 * loan's lifetime; a listener registered for `LoanPenaltyRecorded`
 * receives one event per overdue installment.
 *
 * =============================================================================
 * What this command deliberately does NOT do
 * =============================================================================
 *   - It does NOT accrue penalties daily. See §"THE FROZEN FISCAL
 *     MODEL".
 *
 *   - It does NOT open a transaction of its own. Each `detect()` call
 *     is its own transaction (per A.33).
 *
 *   - It does NOT re-throw per-installment failures. See §"The
 *     per-installment failure isolation".
 *
 *   - It does NOT compute the penalty amount. The amount is computed by
 *     the service (A.33) from the installment's overdue balance and the
 *     policy's `arrears.penalty_rate` and `arrears.cap`. The command
 *     does no arithmetic.
 *
 *   - It does NOT write to `loan_installments`. The installment's
 *     status transition to `overdue` is performed by the service
 *     (A.33, step 11).
 *
 *   - It does NOT touch the Wallet directly. Every Wallet interaction
 *     flows through `LoanArrearsService::detect()`, which calls
 *     `ObligationService::record()`.
 *
 *   - It does NOT send notifications. A future CR that wants to notify
 *     the borrower or the operator of a new arrearage would add the
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
 *   Phase 2C Decision Register, 2026-09-12       — D133 (arrears rules),
 *                                                  B1 (reference types),
 *                                                  B3 (recording pattern)
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §4.2 (the penalty
 *                                                  table's
 *                                                  specification),
 *                                                  §4.3 (this command's
 *                                                  semantics), §4.4
 *                                                  (policy-data-driven
 *                                                  amounts)
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.7                                 — `LoanPolicyKeys`
 *   Phase 2C A.16                                — `loan_penalty_accruals`
 *                                                  migration
 *   Phase 2C A.25                                — `LoanPenaltyAccrual`
 *   Phase 2C A.33                                — `LoanArrearsService`
 *                                                  (the per-installment
 *                                                  delegation target)
 *   Phase 2C A.44                                — `LoanEnteredArrears`
 *                                                  (the once-per-loan
 *                                                  event)
 *   Phase 2C A.45                                — `LoanPenaltyRecorded`
 *                                                  (the per-installment
 *                                                  event)
 *   Phase 2C A.88                                — `AccrueInterestCommand`
 *                                                  (the structural
 *                                                  sibling)
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
 *   Step 5 (manifest) A.89                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Console\Commands
 * @version 1.0.0
 */
final class DetectArrearsCommand extends Command
{
    /**
     * The console command name.
     *
     * Uses the domain-prefixed `loan:` convention, matching the platform-
     * wide command-naming pattern.
     *
     * @var string
     */
    protected $signature = 'loan:detect-arrears
        {--as-of= : The reference date for the arrears eligibility check (YYYY-MM-DD). Defaults to today.}
        {--loan-id= : Restrict to a single loan by primary key.}
        {--dry-run : Report what would be penalized without writing.}
        {--chunk=100 : Number of loans to fetch per chunk.}';

    /**
     * The console command description.
     *
     * Deliberately specific: "one penalty per installment" preempts a
     * reader's assumption that the daily cadence implies daily penalty
     * accrual. See the class docblock §"THE FROZEN FISCAL MODEL".
     *
     * @var string
     */
    protected $description = 'Detect overdue installments and record penalties (one penalty per installment, not daily).';

    /**
     * The set of loan statuses that are arrears-eligible.
     *
     * Matches `LoanArrearsService::assertLoanIsArrearsEligible()` (A.33).
     * The command filters loans to this set at the query, so that the
     * service is never called for an ineligible loan.
     *
     * @var list<LoanStatus>
     */
    private const ARREARS_ELIGIBLE_LOAN_STATUSES = [
        LoanStatus::Active,
        LoanStatus::InArrears,
    ];

    /**
     * The set of installment statuses whose penalty has not yet been
     * accrued and whose grace threshold determines penalizability.
     *
     * The set is `pending`, `partial`. `overdue` is excluded: an overdue
     * installment by definition already has its penalty accrued (its
     * status was transitioned to `overdue` by a prior `detect()` call in
     * the same transaction as the penalty recording). `settled` and
     * `superseded` are excluded: both are terminal.
     *
     * @var list<InstallmentStatus>
     */
    private const PENALIZABLE_INSTALLMENT_STATUSES = [
        InstallmentStatus::Pending,
        InstallmentStatus::Partial,
    ];

    /**
     * The maximum chunk size the `--chunk` option accepts.
     *
     * A defensive ceiling against an operator who passes a very large
     * value that would exceed the shared-host memory.
     */
    private const MAX_CHUNK_SIZE = 1000;

    /**
     * Constructor.
     *
     * One dependency: the arrears service (A.33). The service opens its
     * own transaction per `detect()` call; the command does not manage
     * transactions.
     *
     * @param LoanArrearsService $arrearsService
     */
    public function __construct(
        private readonly LoanArrearsService $arrearsService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * See the class docblock §"The iteration strategy" for the four
     * phases. Every phase is wrapped in a per-installment
     * `try/catch (Throwable)`; the command never propagates a per-
     * installment failure to the caller.
     *
     * @return int  The exit code: `self::SUCCESS` (0) on a completed
     *              run (regardless of per-installment failures),
     *              `self::FAILURE` (1) on a setup failure.
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
            'Detecting arrears as of %s%s.',
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
     * Same sentinel behavior as A.88's `resolveLoanId()`: a
     * non-numeric value produces a warning and a `-1` sentinel, which
     * filters to zero rows. See A.88's class docblock for the
     * rationale on the lenient treatment.
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
     * Counters:
     *
     *   loans_processed        — number of loans iterated.
     *   loans_skipped_disabled — number of loans whose product disables
     *                            arrears.
     *   installments_examined  — number of installments whose grace
     *                            threshold has been crossed.
     *   installments_penalized — number of penalties the service
     *                            recorded (non-null return).
     *   installments_skipped   — number of installments the service
     *                            skipped (null return).
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
            'loans_processed'        => 0,
            'loans_skipped_disabled' => 0,
            'installments_examined'  => 0,
            'installments_penalized' => 0,
            'installments_skipped'   => 0,
            'installments_failed'    => 0,
        ];

        $query = Loan::query()
            ->whereIn('status', array_map(
                static fn (LoanStatus $s): string => $s->value,
                self::ARREARS_ELIGIBLE_LOAN_STATUSES,
            ))
            ->orderBy('id');

        if ($loanId !== null) {
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
     * @param Loan               $loan
     * @param Carbon             $asOf
     * @param bool               $dryRun
     * @param array<string, int> $counters   Passed by reference.
     * @return void
     */
    private function processLoan(
        Loan $loan,
        Carbon $asOf,
        bool $dryRun,
        array &$counters,
    ): void {
        // ---------------------------------------------------------------------
        // Resolve the frozen policy and extract the arrears parameters.
        //
        // When the policy is missing or malformed, the command logs and
        // skips the loan. The service (A.33) would throw LogicException
        // on the same condition; the command's early check avoids the
        // exception overhead and produces a clearer log line.
        // ---------------------------------------------------------------------
        $arrearsConfig = $this->resolveArrearsConfig($loan);

        if ($arrearsConfig === null) {
            $counters['loans_skipped_disabled']++;
            return;
        }

        [$arrearsEnabled, $graceDays] = $arrearsConfig;

        if (!$arrearsEnabled) {
            $counters['loans_skipped_disabled']++;
            return;
        }

        // ---------------------------------------------------------------------
        // Compute the grace threshold date.
        //
        // An installment becomes penalizable when its grace threshold
        // has been crossed, i.e. when `asOf > due_date + grace_days`.
        // The threshold itself is a boundary day; the penalty applies
        // strictly AFTER it.
        // ---------------------------------------------------------------------
        $threshold = $asOf->copy()->subDays($graceDays);

        // ---------------------------------------------------------------------
        // Query the loan's eligible installments.
        //
        // The filters mirror the service's guards (A.33):
        //   - status in (pending, partial),
        //   - due_date <= threshold.
        //
        // The `remaining_total > 0` filter is not expressed at the SQL
        // level because it depends on a computed column; the command
        // performs the check after loading.
        // ---------------------------------------------------------------------
        /** @var Collection<int, LoanInstallment> $installments */
        $installments = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereIn('status', array_map(
                static fn (InstallmentStatus $s): string => $s->value,
                self::PENALIZABLE_INSTALLMENT_STATUSES,
            ))
            ->where('due_date', '<', $threshold->toDateString())
            ->orderBy('installment_number')
            ->get();

        foreach ($installments as $installment) {
            // -----------------------------------------------------------------
            // Skip an installment that is already fully settled.
            //
            // A `pending` or `partial` installment whose `remaining_total`
            // is zero is an anomaly (the status column is out of sync
            // with the settled amount). The service would return null
            // for this case defensively; the command skips it without
            // even calling the service.
            // -----------------------------------------------------------------
            if (bccomp($installment->remainingTotal(), '0', 4) === 0) {
                continue;
            }

            $counters['installments_examined']++;

            if ($dryRun) {
                $this->line(sprintf(
                    '  [dry-run] would penalize loan=%d installment=%d (due %s, grace %d days)',
                    $loan->id,
                    $installment->id,
                    $installment->due_date->toDateString(),
                    $graceDays,
                ));
                $counters['installments_skipped']++;
                continue;
            }

            try {
                $accrual = $this->arrearsService->detect(
                    loan:        $loan,
                    installment: $installment,
                    asOf:        $asOf,
                    actorUserId: null,
                );

                if ($accrual === null) {
                    // The service skipped the penalty — an already-
                    // penalized installment, or a zero-amount penalty.
                    // Both are normal.
                    $counters['installments_skipped']++;
                } else {
                    $counters['installments_penalized']++;

                    $this->line(sprintf(
                        '  penalized loan=%d installment=%d amount=%s',
                        $loan->id,
                        $installment->id,
                        (string) $accrual->penalty_amount,
                    ));
                }
            } catch (Throwable $e) {
                $counters['installments_failed']++;

                // -----------------------------------------------------------------
                // Per-installment failure isolation: log and continue.
                //
                // The service opens its own transaction per `detect()`
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
    // Private — policy resolution
    // =========================================================================

    /**
     * Resolve the loan's frozen policy's arrears parameters.
     *
     * Returns a two-element array `[bool $enabled, int $graceDays]` on
     * success, or null when the policy is missing or malformed. A
     * malformed policy produces a warning log line and a skip, not a
     * per-installment failure — the anomaly is loan-level, not
     * installment-level.
     *
     * The method reads the FROZEN policy version (via
     * `$loan->policyVersion`, A.18), not the product's current policy
     * (per the B6/Extra-2 freeze: a loan's rules are fixed at approval).
     *
     * @param Loan $loan
     * @return array{0: bool, 1: int}|null
     */
    private function resolveArrearsConfig(Loan $loan): ?array
    {
        $loan->loadMissing('policyVersion');

        /** @var VersionedPolicy|null $policy */
        $policy = $loan->policyVersion;

        if ($policy === null) {
            $this->warn(sprintf(
                '  skipped loan=%d: no frozen policy version.',
                $loan->id,
            ));

            return null;
        }

        $payload = $policy->payload;

        if (!is_array($payload)) {
            $this->warn(sprintf(
                '  skipped loan=%d: policy payload is not an array.',
                $loan->id,
            ));

            return null;
        }

        $arrearsSection = $payload[LoanPolicyKeys::ARREARS_SECTION] ?? null;

        if (!is_array($arrearsSection)) {
            $this->warn(sprintf(
                '  skipped loan=%d: policy is missing the "%s" section.',
                $loan->id,
                LoanPolicyKeys::ARREARS_SECTION,
            ));

            return null;
        }

        $enabled = ($arrearsSection[LoanPolicyKeys::ARREARS_ENABLED] ?? false) === true;

        if (!$enabled) {
            return [false, 0];
        }

        $graceDaysRaw = $arrearsSection[LoanPolicyKeys::ARREARS_GRACE_DAYS] ?? 0;

        if (is_int($graceDaysRaw)) {
            return [true, $graceDaysRaw];
        }

        if (is_string($graceDaysRaw) && preg_match('/^\d+$/', $graceDaysRaw)) {
            return [true, (int) $graceDaysRaw];
        }

        $this->warn(sprintf(
            '  skipped loan=%d: "%s.%s" is not a non-negative integer.',
            $loan->id,
            LoanPolicyKeys::ARREARS_SECTION,
            LoanPolicyKeys::ARREARS_GRACE_DAYS,
        ));

        return null;
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
            '%s: %d loans (%d skipped, arrears disabled), '
            . '%d installments examined, %d penalized, %d skipped, %d failed.',
            $prefix,
            $counters['loans_processed'],
            $counters['loans_skipped_disabled'],
            $counters['installments_examined'],
            $counters['installments_penalized'],
            $counters['installments_skipped'],
            $counters['installments_failed'],
        ));

        if ($counters['installments_failed'] > 0) {
            $this->warn(sprintf(
                '%d installment(s) failed. Review the log above for details. '
                . 'The next scheduled run will retry them; the penalty '
                . 'idempotency ensures no duplicate is produced.',
                $counters['installments_failed'],
            ));
        }
    }
}