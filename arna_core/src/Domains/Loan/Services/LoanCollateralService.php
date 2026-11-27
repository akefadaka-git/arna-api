<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\Arna\Core\Domains\Loan\Enums\CollateralStatus;
use MaaleArna\Arna\Core\Domains\Loan\Enums\CollateralType;
use MaaleArna\Arna\Core\Domains\Loan\Events\LoanCollateralReleased;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanCollateral;
use MaaleArna\Arna\Core\Domains\Loan\Support\LoanPolicyKeys;
use MaaleArna\Arna\Core\Domains\Shareholder\Models\ShareHolding;

/**
 * LoanCollateralService
 *
 * Owns the two collateral-record operations of the Loan domain: the
 * REGISTRATION of a new collateral against a loan, and the RELEASE of a
 * previously-registered collateral.
 *
 * =============================================================================
 * Collateral pledges remain tenant-bound and coverability-checked.
 * @package MaaleArna\Arna\Core\Domains\Loan\Services
 * @version 1.1.2
 */
final class LoanCollateralService
{
    /**
     * The monetary/quantity scale used for the coverage comparison.
     *
     * Matches the `decimal(20,4)` columns on `share_holdings.current_quantity`
     * and `loan_collaterals.pledged_quantity`.
     */
    private const FINAL_SCALE = 4;

    // =========================================================================
    // Public API — register
    // =========================================================================

    /**
     * Register a new collateral against a loan.
     *
     * See the class docblock §"The register() flow" for the full sequence.
     * The request-layer validation is assumed to have run; this service
     * enforces the domain rules the request cannot enforce.
     *
     * @param Loan                $loan                  The loan the
     *                                                   collateral is
     *                                                   registered
     *                                                   against. Must be
     *                                                   non-terminal.
     * @param CollateralType      $collateralType        The classifier of
     *                                                   the collateral.
     * @param string              $description           The free-text
     *                                                   description.
     * @param string|null         $externalReference     An optional
     *                                                   external reference.
     * @param int|null            $pledgedShareholdingId For a
     *                                                   shareholding-typed
     *                                                   collateral, the
     *                                                   referenced
     *                                                   ShareHolding. Must
     *                                                   resolve to an
     *                                                   existing row. Null
     *                                                   for external-typed.
     * @param string|null         $pledgedQuantity       For a
     *                                                   shareholding-typed
     *                                                   collateral, the
     *                                                   pledged quantity as
     *                                                   a decimal string.
     *                                                   Null for
     *                                                   external-typed.
     * @param string|null         $valuationAmount       The valuation
     *                                                   amount, if any.
     * @param string|null         $valuationCurrency     The ISO 4217
     *                                                   currency code, if
     *                                                   any.
     * @param string|null         $valuationDate         The valuation date,
     *                                                   as an ISO 8601
     *                                                   string, if any.
     * @param int|null            $actorUserId           The registering
     *                                                   user, or null for
     *                                                   a system-initiated
     *                                                   registration.
     * @return LoanCollateral  The freshly-inserted, refreshed collateral
     *                         row.
     *
     * @throws ValidationException       When the loan is terminal, when
     *                                   the product policy forbids the
     *                                   type, when the pledged holding
     *                                   does not exist, when the pledge's
     *                                   owner does not match the loan's
     *                                   borrower, or when the pledge
     *                                   quantity is not covered.
     * @throws LogicException            When the product policy payload is
     *                                   malformed (non-array payload).
     */
    public function register(
        Loan $loan,
        CollateralType $collateralType,
        string $description,
        ?string $externalReference = null,
        ?int $pledgedShareholdingId = null,
        ?string $pledgedQuantity = null,
        ?string $valuationAmount = null,
        ?string $valuationCurrency = null,
        ?string $valuationDate = null,
        ?int $actorUserId = null,
    ): LoanCollateral {
        return DB::transaction(function () use (
            $loan,
            $collateralType,
            $description,
            $externalReference,
            $pledgedShareholdingId,
            $pledgedQuantity,
            $valuationAmount,
            $valuationCurrency,
            $valuationDate,
            $actorUserId,
        ): LoanCollateral {
            // -----------------------------------------------------------------
            // 1. Terminal-state exclusion.
            // -----------------------------------------------------------------
            $this->assertLoanAcceptsNewCollateral($loan);

            // -----------------------------------------------------------------
            // 2. Product policy check — permitted types.
            // -----------------------------------------------------------------
            $this->assertCollateralTypePermittedByProductPolicy(
                $loan,
                $collateralType,
            );

            // -----------------------------------------------------------------
            // 3. Pledge linkage validation, for shareholding-typed
            //    collateral.
            // -----------------------------------------------------------------
            if ($collateralType === CollateralType::Shareholding) {
                $this->assertPledgeIsCoverable(
                    $loan,
                    $pledgedShareholdingId,
                    $pledgedQuantity,
                );
            }

            // -----------------------------------------------------------------
            // 4. INSERT the collateral row.
            //
            // The migration A.11 provides defaults for `status` and
            // `registered_at`; this service relies on those defaults
            // and does NOT supply them. The `registered_by` column is
            // nullable and populated with the caller's actor id.
            //
            // The columns NOT supplied on a per-type basis (the pledge
            // pair for an external collateral, the valuation triple for
            // an unvalued shareholding collateral) are passed through as
            // null. The request-layer per-type validation already
            // enforces the mutual-exclusion rules; the service does not
            // re-enforce them.
            // -----------------------------------------------------------------
            /** @var LoanCollateral $collateral */
            $collateral = LoanCollateral::create([
                'loan_id'                 => $loan->id,
                'collateral_type'         => $collateralType,
                'description'             => $description,
                'external_reference'      => $externalReference,
                'pledged_shareholding_id' => $pledgedShareholdingId,
                'pledged_quantity'        => $pledgedQuantity,
                'valuation_amount'        => $valuationAmount,
                'valuation_currency'      => $valuationCurrency,
                'valuation_date'          => $valuationDate,
                'registered_by'           => $actorUserId,
            ]);

            return $collateral->refresh();
        });
    }

    // =========================================================================
    // Public API — release
    // =========================================================================

    /**
     * Release a registered collateral.
     *
     * See the class docblock §"The release() flow" for the full sequence.
     *
     * @param Loan           $loan          The loan the collateral
     *                                      belongs to. Used as the
     *                                      primary model on the
     *                                      dispatched event.
     * @param LoanCollateral $collateral    The collateral to release. Must
     *                                      currently be in the
     *                                      `registered` state.
     * @param string         $reason        The operator's stated reason
     *                                      for the release. Required,
     *                                      non-empty.
     * @param int|null       $actorUserId   The releasing user, or null for
     *                                      a system-initiated release.
     * @return LoanCollateral  The refreshed collateral in `released`
     *                         state.
     *
     * @throws ValidationException  When the collateral is not currently
     *                              registered.
     */
    public function release(
        Loan $loan,
        LoanCollateral $collateral,
        string $reason,
        ?int $actorUserId = null,
    ): LoanCollateral {
        return DB::transaction(function () use (
            $loan,
            $collateral,
            $reason,
            $actorUserId,
        ): LoanCollateral {
            // -----------------------------------------------------------------
            // 1. Release-state gate.
            // -----------------------------------------------------------------
            $this->assertCollateralIsReleasable($collateral);

            // -----------------------------------------------------------------
            // 2. Write the release triple.
            //
            // The migration A.11 declares `released_at` as nullable (no
            // default) and `release_reason` and `released_by` as nullable.
            // This service writes all three explicitly. The `status`
            // column is written through explicit attribute assignment and
            // persisted with `save()` — same pattern as
            // `LoanApprovalService::reject()` writing `decision_date`.
            // -----------------------------------------------------------------
            $now = Carbon::now();

            $collateral->status         = CollateralStatus::Released;
            $collateral->released_at    = $now;
            $collateral->release_reason = $reason;
            $collateral->released_by    = $actorUserId;
            $collateral->save();

            // Refresh so the event carries the fully-hydrated
            // post-release state.
            $collateral = $collateral->refresh();

            // -----------------------------------------------------------------
            // 3. Register the after-commit event.
            //
            // The event is constructed HERE, because the service has the
            // domain-specific data — the loan, the released collateral,
            // the reason, and the actor. The `registerAfterCommitEvent()`
            // helper handles the config gate and the `DB::afterCommit()`
            // registration.
            // -----------------------------------------------------------------
            $event = new LoanCollateralReleased(
                $loan,
                $collateral,
                $reason,
                $actorUserId,
            );

            $this->registerAfterCommitEvent($event);

            return $collateral;
        });
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert the loan's status is not terminal.
     *
     * See the class docblock §"Terminal-state exclusion" for the rationale.
     * The terminal set is derived from the `LoanStatus` enum, not
     * re-enumerated here.
     *
     * @param Loan $loan
     * @return void
     *
     * @throws ValidationException
     */
    private function assertLoanAcceptsNewCollateral(Loan $loan): void
    {
        if (!$loan->status->isTerminal()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'A loan in status "%s" cannot accept a new collateral; '
                    . 'the loan\'s lifecycle has reached a terminal '
                    . 'state.',
                    $loan->status->value,
                ),
            ],
        ]);
    }

    /**
     * Assert the proposed collateral type is permitted by the product's
     * collateral policy.
     *
     * See the class docblock §"The product policy check" for the full
     * resolution sequence. If neither the loan's frozen policy nor the
     * product's current policy is available, or if the policy payload
     * does not declare a `collateral` section, or if the section's
     * `permitted_types` list is empty, the method is a no-op — the
     * registration is permitted.
     *
     * @param Loan           $loan
     * @param CollateralType $collateralType
     * @return void
     *
     * @throws ValidationException
     * @throws LogicException  When the policy payload is a non-array.
     */
    private function assertCollateralTypePermittedByProductPolicy(
        Loan $loan,
        CollateralType $collateralType,
    ): void {
        $section = $this->resolveCollateralPolicySection($loan);

        if ($section === null) {
            // No policy to consult, or a section that is missing. The
            // registration is permitted. See the class docblock
            // §"Missing-section leniency".
            return;
        }

        $permittedTypes = $section[LoanPolicyKeys::COLLATERAL_PERMITTED_TYPES] ?? null;

        if (!is_array($permittedTypes) || $permittedTypes === []) {
            // No permitted-types list, or an empty one. Both are
            // treated as "no restrictions". See the class docblock
            // §"Missing-section leniency".
            return;
        }

        if (in_array($collateralType->value, $permittedTypes, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'collateral_type' => [
                sprintf(
                    'The product\'s collateral policy does not permit '
                    . 'collateral of type "%s". The policy permits: %s.',
                    $collateralType->value,
                    implode(', ', array_map(
                        static fn (mixed $v): string => (string) $v,
                        $permittedTypes,
                    )),
                ),
            ],
        ]);
    }

    /**
     * Resolve the loan's governing product policy's `collateral` section.
     *
     * The resolution sequence is documented in the class docblock
     * §"The product policy check":
     *
     *   1. If the loan has a `frozen_policy_version_id`, use that policy.
     *   2. Else, use the loan's product's current
     *      `product_rules_policy_version_id`.
     *   3. If neither is set, return null.
     *
     * When a policy is resolved, its `payload` is read and the
     * `COLLATERAL_SECTION` sub-object is returned. A non-array payload
     * is a data defect and throws `LogicException`; a missing section is
     * treated as "no restrictions" and returns null.
     *
     * @param Loan $loan
     * @return array<string, mixed>|null
     *
     * @throws LogicException  When the resolved policy's payload is a
     *                         non-array.
     */
    private function resolveCollateralPolicySection(Loan $loan): ?array
    {
        $loan->loadMissing(['policyVersion', 'product.policyVersion']);

        $policy = $loan->policyVersion ?? $loan->product?->policyVersion;

        if ($policy === null) {
            return null;
        }

        $payload = $policy->payload;

        if (!is_array($payload)) {
            throw new LogicException(
                sprintf(
                    'Policy version id=%d has a non-array payload. A '
                    . 'loan_product_rules payload must be a JSON object.',
                    $policy->id,
                ),
            );
        }

        $section = $payload[LoanPolicyKeys::COLLATERAL_SECTION] ?? null;

        if (!is_array($section)) {
            return null;
        }

        return $section;
    }

    /**
     * Validate the pledge linkage for a shareholding-typed collateral.
     *
     * See the class docblock §"The pledge linkage validation" for the
     * full sequence and the rationale on why the referenced holding is
     * NOT reduced by this operation.
     *
     * Behaviour matrix:
     *
     *   pledgedShareholdingId | pledgedQuantity | Outcome
     *   ----------------------+-----------------+----------------------
     *   null                  | any             | 422 (required field)
     *   any                   | null / empty    | 422 (required field)
     *   supplied              | supplied        | resolve holding
     *     · holding not found                    | 422 (existence)
     *     · holding found, wrong owner           | 422 (ownership)
     *     · holding found, insufficient qty      | 422 (coverage)
     *     · holding found, all checks pass       | OK
     *
     * @param Loan        $loan
     * @param int|null    $pledgedShareholdingId
     * @param string|null $pledgedQuantity
     * @return void
     *
     * @throws ValidationException
     */
    private function assertPledgeIsCoverable(
        Loan $loan,
        ?int $pledgedShareholdingId,
        ?string $pledgedQuantity,
    ): void {
        // ---------------------------------------------------------------------
        // Defensive null checks. The request validates these fields as
        // required-for-shareholding; the service re-checks so a future
        // programmatic caller that bypasses the request layer receives
        // a field-keyed 422, not a TypeError.
        // ---------------------------------------------------------------------
        if ($pledgedShareholdingId === null) {
            throw ValidationException::withMessages([
                'pledged_shareholding_id' => [
                    'A pledged shareholding is required for shareholding-typed collateral.',
                ],
            ]);
        }

        if ($pledgedQuantity === null || trim($pledgedQuantity) === '') {
            throw ValidationException::withMessages([
                'pledged_quantity' => [
                    'A pledged quantity is required for shareholding-typed collateral.',
                ],
            ]);
        }

        // ---------------------------------------------------------------------
        // Existence check. The referenced holding MUST resolve to an
        // existing row. See the class docblock §"The pledge linkage
        // validation", check 1, for the rationale.
        // ---------------------------------------------------------------------
        /** @var ShareHolding|null $shareHolding */
        $shareHolding = ShareHolding::query()
            ->whereKey($pledgedShareholdingId)
            ->first();

        if ($shareHolding === null) {
            throw ValidationException::withMessages([
                'pledged_shareholding_id' => [
                    sprintf(
                        'No shareholding exists with id=%d. The pledge '
                        . 'must reference an existing holding.',
                        $pledgedShareholdingId,
                    ),
                ],
            ]);
        }

        // ---------------------------------------------------------------------
        // Ownership check — the holding must belong to the loan's
        // borrower.
        // ---------------------------------------------------------------------
        if ((int) $shareHolding->shareholder_id !== (int) $loan->borrower_shareholder_id) {
            throw ValidationException::withMessages([
                'pledged_shareholding_id' => [
                    sprintf(
                        'The referenced shareholding (id=%d) does not '
                        . 'belong to the borrower of this loan '
                        . '(borrower_shareholder_id=%d).',
                        (int) $shareHolding->getKey(),
                        (int) $loan->borrower_shareholder_id,
                    ),
                ],
            ]);
        }

        // ---------------------------------------------------------------------
        // Coverage check — the holding's current_quantity must be
        // greater than or equal to the proposed pledged_quantity,
        // compared at scale 4 via bcmath.
        // ---------------------------------------------------------------------
        $held    = (string) $shareHolding->current_quantity;
        $pledged = trim($pledgedQuantity);

        if (bccomp($held, $pledged, self::FINAL_SCALE) < 0) {
            throw ValidationException::withMessages([
                'pledged_quantity' => [
                    sprintf(
                        'The borrower holds %s shares in the referenced '
                        . 'class; the proposed pledge of %s exceeds the '
                        . 'available quantity.',
                        $held,
                        $pledged,
                    ),
                ],
            ]);
        }
    }

    /**
     * Assert the collateral is currently in a releasable state.
     *
     * See the class docblock §"The release state gate" for the rationale.
     * The only releasable state is `registered`.
     *
     * The comparison is performed directly against the
     * `CollateralStatus` enum case. The `LoanCollateral` model casts
     * `status` to the enum, so the attribute access yields an enum
     * instance and the `===` check is correct. See the class docblock
     * §"Status comparison — direct enum identity".
     *
     * @param LoanCollateral $collateral
     * @return void
     *
     * @throws ValidationException
     */
    private function assertCollateralIsReleasable(LoanCollateral $collateral): void
    {
        if ($collateral->status === CollateralStatus::Registered) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'A collateral in status "%s" cannot be released; only '
                    . 'a "registered" collateral can be released.',
                    $collateral->status->value,
                ),
            ],
        ]);
    }

    // =========================================================================
    // Private — event registration
    // =========================================================================

    /**
     * Register an event for after-commit dispatch, gated on
     * `config('loan.audit.emit_events')`.
     *
     * The release is not tied to a state-machine transition — the
     * collateral's status is orthogonal to the loan's — so this helper
     * mirrors the pattern used by `LoanRepaymentService` (A.31) and
     * `LoanArrearsService` (A.33) for their non-transition-tied events.
     *
     * The config gate defaults to `true`, per `Config/loan.php` (A.1).
     * When the config is false, the event is not registered; the
     * collateral's status change is still persisted, and its own
     * `released_at` / `released_by` / `release_reason` columns carry the
     * audit information.
     *
     * @param object $event
     * @return void
     */
    private function registerAfterCommitEvent(object $event): void
    {
        if (!config('loan.audit.emit_events', true)) {
            return;
        }

        DB::afterCommit(static function () use ($event): void {
            Event::dispatch($event);
        });
    }
}