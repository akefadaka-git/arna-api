<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\CollateralStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\CollateralType;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanCollateralReleased;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;
use MaaleArna\MASES\Core\Domains\Loan\Support\LoanPolicyKeys;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;

/**
 * LoanCollateralService
 *
 * Owns the two collateral-record operations of the Loan domain: the
 * REGISTRATION of a new collateral against a loan, and the RELEASE of a
 * previously-registered collateral.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.1.0 — Three changes delivered together:
 *
 *           (a) `assertPledgeIsCoverable()` — a missing referenced
 *               `ShareHolding` row no longer raised
 *               `ModelNotFoundException`. The FK was stored as supplied
 *               and the ownership/coverage checks were skipped.
 *
 *           (b) `assertCollateralIsReleasable()` — a new private
 *               `normaliseStatus()` helper routed the status check
 *               through a string normalisation.
 *
 *           (c) Docblock rewritten to describe (a) and (b).
 *
 * 1.1.1 — B6 audit remediation. Removed the `normaliseStatus()` helper
 *         and restored a direct enum comparison in
 *         `assertCollateralIsReleasable()`.
 *
 *         BACKGROUND
 *         ----------
 *         The helper was introduced under the diagnosis that a
 *         string-vs-enum comparison was producing false negatives in the
 *         guard, on the theory that "whether the model casts `status`
 *         depends on the runtime `$casts` configuration". The diagnosis
 *         was wrong: the failure that prompted it was caused by the
 *         mass-assignment opening of `LoanCollateral::$fillable` (see
 *         the B2 remediation on that model), not by a casting
 *         ambiguity.
 *
 *         EFFECT
 *         ------
 *         Behaviour-neutral. The guard produces the same accept/reject
 *         decision for every reachable input.
 *
 * 1.1.2 — B3 audit remediation. Reverted the v1.1.0 pledge-leniency
 *         change: a supplied `pledged_shareholding_id` that does not
 *         resolve to an existing `ShareHolding` row is now rejected.
 *
 *         BACKGROUND
 *         ----------
 *         The v1.1.0 change tolerated a missing referenced row on the
 *         theory that the FK column "carries (or will carry) a
 *         database-level constraint that is the ultimate authority",
 *         and that certain programmatic callers (batch staging, test
 *         fixtures) might legitimately supply non-existent ids. The
 *         first half of that rationale does not hold in Phase 2C: the
 *         migration A.11 declares `pledged_shareholding_id` as a plain
 *         `unsignedBigInteger()->nullable()` with NO database-level
 *         FK. The FK is explicitly deferred to
 *         CR-2C-COLLATERAL-PLEDGE-FK-001. Until that CR lands, the
 *         service IS the referential-integrity mechanism, and
 *         tolerating a non-resolving id permits a dangling reference
 *         (a data-integrity defect) to be persisted.
 *
 *         The second half of the rationale — a caller that legitimately
 *         needs to stage non-existent references — was not corroborated
 *         by any named production caller in the remediation record. The
 *         only call sites that relied on the leniency were two test
 *         fixtures (`test_store_creates_shareholding_collateral` and
 *         `test_store_accepts_shareholding_with_complete_valuation_triple`)
 *         that constructed a shareholding-typed collateral with
 *         `pledged_shareholding_id => 42` — a value that does not
 *         resolve to any row. Those fixtures are the defect; the
 *         service was accommodating them.
 *
 *         DECISION
 *         --------
 *         Restore strict existence enforcement at the service layer.
 *         The two fixtures are corrected to create a real `ShareHolding`
 *         first and pass its id — that is a fixture-file change, not a
 *         production-contract change.
 *
 *         EXCEPTION CLASS
 *         ---------------
 *         Pre-v1.1.0 raised `ModelNotFoundException` (HTTP 404). This
 *         revision raises `ValidationException` (HTTP 422) instead, and
 *         keys the error on `pledged_shareholding_id`.
 *
 *         The choice of 422 over 404 is deliberate: the value is a
 *         request-BODY field, not a URL path segment, and Laravel's
 *         404 is conventionally reserved for "no route matched the
 *         request" or "the addressed resource does not exist". The two
 *         adjacent checks in the same method — ownership and coverage —
 *         already use `ValidationException` with field keys. Aligning
 *         the existence check with them gives the endpoint a single,
 *         consistent error surface: a shareholding-typed registration
 *         that fails any of its three pledge checks returns a 422 with
 *         a `pledged_shareholding_id` or `pledged_quantity` field
 *         error.
 *
 *         The LENIENCY is what this revision reverts. The exception
 *         CLASS is set to the form consistent with the surrounding
 *         validation idiom. Both choices are documented here so a
 *         future reader can distinguish them.
 *
 *         EFFECT
 *         ------
 *         A shareholding-typed registration whose
 *         `pledged_shareholding_id` does not resolve now returns 422
 *         instead of silently persisting a dangling FK. The two
 *         affected fixtures are updated separately.
 *
 * =============================================================================
 * Scope — the two operations
 * =============================================================================
 * This service owns exactly TWO operations:
 *
 *   register()  — attach a new collateral to a loan.
 *
 *   release()   — discharge a registered collateral, transitioning the
 *                 collateral row from `registered` to `released` and
 *                 recording the operator's reason.
 *
 * The index endpoint (`GET /loans/{loan}/collateral`, A.79) is a pure read
 * served directly by `LoanCollateralController` through
 * `LoanCollateralRepository` (A.58). No service owns a read operation in
 * the Loan domain.
 *
 * =============================================================================
 * What this service is NOT
 * =============================================================================
 *   · It does NOT touch the Wallet. A collateral is NOT Wallet money (per
 *     A10), and neither registration nor release produces or discharges
 *     any Wallet obligation, payment, or entry. The class has no
 *     Wallet-domain dependency — deliberately.
 *
 *   · It does NOT mutate the Shareholder ledger. A shareholding-typed
 *     collateral references a `pledged_shareholding_id`, but the Loan
 *     domain MUST NOT modify `share_holdings` or `share_holding_entries`
 *     directly. The dedicated pledge relationship — with its own
 *     Shareholder-side booking — is deferred to
 *     CR-2C-COLLATERAL-PLEDGE-FK-001. In Phase 2C, a shareholding-typed
 *     registration validates the referenced holding; it does NOT
 *     reserve, reduce, or lien the holding's `current_quantity`.
 *
 *   · It does NOT evaluate the product's collateral-required flag as an
 *     APPROVAL precondition. Whether a loan may be approved without a
 *     collateral is `LoanApprovalService`'s concern (A.29) — the frozen
 *     A.29 flow does not currently enforce it, and the enforcement is
 *     recorded as deferred. This service reads the product policy to
 *     determine WHICH TYPES the product permits, not WHETHER a
 *     collateral is required.
 *
 *   · It does NOT open the file system or read any configuration. Every
 *     rule the service enforces comes from a persisted row (the loan, the
 *     shareholding, the versioned policy) or from
 *     `config('loan.audit.*')` for the after-commit event gate.
 *
 * =============================================================================
 * The register() flow
 * =============================================================================
 * The operation performs the following steps, in this order, inside ONE
 * transaction:
 *
 *   1. Assert the loan's status is not terminal — the four terminal
 *      statuses (`rejected`, `written_off`, `settled`, `closed`) cannot
 *      accept a new collateral. See §"Terminal-state exclusion".
 *
 *   2. Resolve the product's collateral policy section and assert the
 *      proposed `collateral_type` is in its `permitted_types` list, when
 *      that list is populated. See §"The product policy check".
 *
 *   3. For a shareholding-typed collateral, resolve the referenced
 *      shareholding and validate:
 *        · the holding exists (`ValidationException` → 422),
 *        · the holding belongs to the loan's borrower
 *          (`ValidationException` → 422),
 *        · the holding's `current_quantity` covers the proposed
 *          `pledged_quantity` (`ValidationException` → 422).
 *      See §"The pledge linkage validation".
 *
 *   4. INSERT the collateral row with `status = registered`, `registered_at
 *      = now()`, and `registered_by = $actorUserId`. The migration A.11
 *      provides defaults for `status` and `registered_at`; this service
 *      relies on those defaults.
 *
 *   5. Return the freshly-inserted, refreshed collateral row.
 *
 * No event is dispatched on registration. The frozen event list (A.38–A.50)
 * contains no `LoanCollateralRegistered` event; the registration's audit
 * trail is the collateral row itself (its `registered_at` and
 * `registered_by` columns).
 *
 * =============================================================================
 * The release() flow
 * =============================================================================
 * The operation performs the following steps, in this order, inside ONE
 * transaction:
 *
 *   1. Assert the collateral is currently in the `registered` state. A
 *      collateral that has already been released cannot be re-released.
 *      See §"The release state gate".
 *
 *   2. Write the release triple on the collateral row: `status = released`,
 *      `released_at = now()`, `release_reason = $reason`,
 *      `released_by = $actorUserId`.
 *
 *   3. Save and refresh the row.
 *
 *   4. Construct the `LoanCollateralReleased` event (A.50) with the
 *      post-release collateral and the loan, and register it for
 *      after-commit dispatch via `DB::afterCommit()`, gated on
 *      `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 *   5. Return the refreshed collateral row.
 *
 * The release is NOT routed through `LoanStateMachine::transition()` (A.26)
 * because the collateral's status is orthogonal to the LOAN's status. The
 * `loan_status_histories` table records loan-status transitions only;
 * collateral-state transitions are recorded solely on the collateral row's
 * own columns. This mirrors `LoanRepaymentService`'s handling of its
 * non-transition-tied events (`LoanRepaymentRecorded`, `LoanInstallmentSettled`).
 *
 * =============================================================================
 * Terminal-state exclusion
 * =============================================================================
 * The four terminal loan statuses cannot accept a new collateral:
 *
 *   rejected     — the application was declined; no security interest was
 *                  ever attached, and none can be.
 *   written_off  — the debt was forgiven; there is no debt to secure.
 *   settled      — the debt was fully repaid; a new collateral would have
 *                  no secured obligation.
 *   closed       — the loan is administratively complete; its security
 *                  history is frozen.
 *
 * Every other status (`draft`, `submitted`, `under_review`, `approved`,
 * `disbursed`, `active`, `in_arrears`, `restructured`) accepts a new
 * collateral. In particular, a draft loan may carry a pre-registered
 * collateral — a real operational pattern when an applicant provides the
 * security documentation along with the application.
 *
 * The terminal set is derived from `LoanStatus::isTerminal()`, not from a
 * re-enumerated list in this service. The service iterates the enum's own
 * terminal values so that a future CR that changes the terminal set
 * updates both the enum and this guard without a third change.
 *
 * =============================================================================
 * The product policy check
 * =============================================================================
 * The service resolves the product's collateral policy section from the
 * loan's governing `loan_product_rules` version:
 *
 *   · If the loan has been approved, the loan's `frozen_policy_version_id`
 *     is set, and its payload is the authoritative source — the same
 *     policy the loan's schedule and its arrears configuration are read
 *     from.
 *
 *   · If the loan is pre-approval (no frozen policy yet), the PRODUCT'S
 *     current `product_rules_policy_version_id` is used instead. This
 *     lets a draft or submitted loan be pre-registered with a collateral
 *     that matches the product's current policy.
 *
 *   · If neither is set (a product with no policy version, and a loan
 *     pre-approval) — the service does NOT reject the registration. A
 *     product without a policy version cannot be approved in Phase 2C
 *     anyway (see `LoanApprovalService::freezePolicyAndRecordApproval`),
 *     so the collateral is registered without a policy check and the
 *     operator will encounter the policy-version gate at approval time.
 *
 * -----------------------------------------------------------------------------
 * Missing-section leniency
 * -----------------------------------------------------------------------------
 * The `collateral` section is documented as REQUIRED on every
 * `loan_product_rules` payload (see `LoanPolicyKeys::COLLATERAL_SECTION`).
 * In practice, the deployed policy payloads created by
 * `LoanProductRulesPolicySeeder` carry only `interest`, `term`, and
 * `arrears` — the `collateral` and `fees` sections are not yet populated.
 *
 * This service therefore treats a missing `collateral` section as "no
 * collateral restrictions declared", not as a data defect. Throwing would
 * make every collateral registration on the current deployment fail; the
 * lenient read lets the flow proceed and defers the strictness to a
 * future CR that populates the section across all policies.
 *
 * When the section IS present and its `permitted_types` list IS populated,
 * the service enforces the list strictly. An empty `permitted_types` list
 * is treated as "no restrictions" rather than "no types permitted"; the
 * latter would be indistinguishable from the former on the wire, and the
 * permissive interpretation is the safer default for an
 * under-specified payload.
 *
 * =============================================================================
 * The pledge linkage validation
 * =============================================================================
 * A shareholding-typed collateral references a Phase 2A `ShareHolding` row
 * via `pledged_shareholding_id`, and pledges a quantity of that holding
 * via `pledged_quantity`.
 *
 * The service enforces three checks, in this order:
 *
 *   1. EXISTENCE. The referenced `ShareHolding` row MUST exist. A
 *      non-resolving id is a 422 with a `pledged_shareholding_id` field
 *      error.
 *
 *      This is the strict behaviour restored by the v1.1.2 B3 revert.
 *      The `loan_collaterals.pledged_shareholding_id` column carries NO
 *      database-level FK in Phase 2C — the FK is deferred to
 *      CR-2C-COLLATERAL-PLEDGE-FK-001. Until that CR lands, the service
 *      is the sole referential-integrity mechanism for this column, and
 *      allowing a non-resolving id would permit a dangling reference to
 *      be persisted.
 *
 *   2. OWNERSHIP. The holding's `shareholder_id` must match the loan's
 *      `borrower_shareholder_id`. A mismatch is a 422 with a
 *      `pledged_shareholding_id` field error. The check confirms the
 *      borrower of the loan is the shareholder whose holding is being
 *      pledged; a borrower cannot pledge a third party's shares through
 *      this path.
 *
 *   3. COVERAGE. The holding's `current_quantity` must be greater than
 *      or equal to `pledged_quantity` at scale 4. The comparison uses
 *      `bccomp` — never PHP's native operators. Insufficient quantity is
 *      a 422 with a `pledged_quantity` field error.
 *
 * The service does NOT reduce the holding's `current_quantity` on
 * registration. The shareholding is NOT reserved, liened, or locked by
 * this operation in Phase 2C. The dedicated pledge booking — which would
 * reduce the holding's available quantity and record a
 * `ShareHoldingEntry` — is deferred to CR-2C-COLLATERAL-PLEDGE-FK-001.
 *
 * -----------------------------------------------------------------------------
 * Why 422 rather than 404 for the existence check
 * -----------------------------------------------------------------------------
 * `pledged_shareholding_id` is a request-BODY field, not a URL path
 * segment. Laravel's 404 is conventionally reserved for "no route
 * matched" or "the addressed resource does not exist"; a missing body
 * reference is a request-validation failure and is correctly a 422.
 * Aligning the existence check with the ownership and coverage checks
 * gives the endpoint a single, consistent error surface: a
 * shareholding-typed registration that fails any of its three pledge
 * checks returns a 422 keyed on `pledged_shareholding_id` or
 * `pledged_quantity`.
 *
 * Pre-v1.1.0 used `ModelNotFoundException` (404). The v1.1.2 revert
 * chooses 422 for the reason above; the LENIENCY is what was reverted,
 * not the exception class.
 *
 * =============================================================================
 * The release state gate
 * =============================================================================
 * The collateral's own status is the sole precondition of a release. The
 * `CollateralStatus` enum (A.3) declares exactly two states and one
 * transition:
 *
 *     registered → released
 *
 * `released` is terminal. A collateral that has already been released
 * cannot be released again; the service rejects a re-release attempt with
 * a 422 carrying a `status` field error, matching the pattern every other
 * sibling service uses for its own state gates (`LoanApprovalService`,
 * `LoanWriteOffService`, `LoanRepaymentService`).
 *
 * -----------------------------------------------------------------------------
 * Status comparison — direct enum identity
 * -----------------------------------------------------------------------------
 * The gate compares the collateral's current status directly against
 * `CollateralStatus::Registered`:
 *
 *     $collateral->status === CollateralStatus::Registered
 *
 * The `LoanCollateral` model casts `status` to the `CollateralStatus`
 * enum, so the attribute access yields an enum instance and the `===`
 * check is the correct, unambiguous form.
 *
 * v1.1.0 briefly routed the check through a private `normaliseStatus()`
 * helper under the mistaken belief that the cast configuration might be
 * uncertain at runtime. It is not — the cast is declared on the model
 * and is a compile-time fact. v1.1.1 reverted the helper under the B6
 * audit remediation. See the class docblock §"Revision history" for the
 * full account.
 *
 * The LOAN's status is NOT a precondition of a release. A loan may be
 * `active`, `in_arrears`, `restructured`, `settled`, or even
 * `written_off` when one of its collaterals is released. The release is
 * orthogonal to the loan's lifecycle: the operation discharges a security
 * interest the lender holds, and that discharge is a valid operation in
 * every loan state where the collateral is still `registered`. The
 * terminal-exclusion rule that governs `register()` does NOT apply to
 * `release()`.
 *
 * =============================================================================
 * Transaction discipline
 * =============================================================================
 * Both operations open their OWN transaction. The service is the TOP-LEVEL
 * entry point for each (the controller calls `register()` or `release()`
 * directly), so there is no outer transaction.
 *
 * For `register()`, the transaction wraps the policy read, the
 * shareholding validation, and the INSERT — atomic under the assumption
 * that no concurrent process modifies the referenced shareholding between
 * the coverage check and the INSERT. This assumption holds in Phase 2C
 * because the Shareholder domain does not perform any operation that
 * would reduce a holding's `current_quantity` in response to a Loan-domain
 * event; a future CR that introduces the two-sided pledge booking would
 * need to re-examine this assumption.
 *
 * For `release()`, the transaction wraps the status update and the
 * after-commit event registration. The event is registered inside the
 * transaction but dispatched after commit.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   · It does NOT open the Wallet, dispatch Wallet events, or reference any
 *     Wallet-domain class. A collateral is not Wallet money (A10).
 *
 *   · It does NOT write to the Shareholder domain. `share_holdings` and
 *     `share_holding_entries` are outside this service's scope. The
 *     deferred pledge booking is CR-2C-COLLATERAL-PLEDGE-FK-001's concern.
 *
 *   · It does NOT dispatch the `LoanCollateralReleased` event itself. It
 *     registers the event for after-commit dispatch via `DB::afterCommit()`;
 *     the actual `Event::dispatch()` call fires after the transaction
 *     commits, matching the pattern of `LoanRepaymentService`'s and
 *     `LoanArrearsService`'s after-commit helpers.
 *
 *   · It does NOT accept a `$status` or `$registered_at` field from the
 *     caller. The status is initialized to `registered` by the migration's
 *     column default, and `registered_at` is likewise defaulted by the
 *     migration. The service does not override either; a caller cannot
 *     falsify the registration metadata.
 *
 *   · It does NOT accept a `$released_at` or `$released_by` field from the
 *     caller on release. The moment is `Carbon::now()` at the release
 *     operation; the actor is the caller's authenticated identity, passed
 *     as `$actorUserId`.
 *
 *   · It does NOT validate the SHAPE of the payload. Every scalar passed to
 *     `register()` has already been validated by
 *     `StoreCollateralRequest` (A.67), including the per-type conditional
 *     requirements (external requires the valuation triple; shareholding
 *     requires the pledge pair and permits an all-or-none valuation
 *     triple). This service enforces the DOMAIN rules the request cannot
 *     enforce: the product policy check and the shareholding reference
 *     validation.
 *
 *   · It does NOT paginate or filter. The repository (A.58) owns reads.
 *
 *   · It does NOT cache. Every call performs its own reads.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A10 (collateral rules),
 *                                                  B4 (collateral
 *                                                  integration), B7 (RBAC)
 *   Phase 2C Clarification Response, 2026-09-12  — C2 (release endpoint),
 *                                                  C3 (Loan-side pledge
 *                                                  sequencing)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §3
 *   Phase 2C A.3                                 — `CollateralStatus`
 *   Phase 2C A.4                                 — `CollateralType`
 *   Phase 2C A.7                                 — `LoanPolicyKeys`
 *                                                  (the collateral
 *                                                  section's key set)
 *   Phase 2C A.11                                — `loan_collaterals`
 *                                                  migration (no FK on
 *                                                  `pledged_shareholding_id`
 *                                                  in Phase 2C)
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.20                                — `LoanCollateral`
 *   Phase 2C A.29                                — `LoanApprovalService`
 *                                                  (the sibling service
 *                                                  that does NOT enforce
 *                                                  the collateral-
 *                                                  required flag in
 *                                                  Phase 2C)
 *   Phase 2C A.31                                — `LoanRepaymentService`
 *                                                  (the sibling whose
 *                                                  after-commit event
 *                                                  pattern this service
 *                                                  mirrors)
 *   Phase 2C A.50                                — `LoanCollateralReleased`
 *                                                  (the release event)
 *   Phase 2C A.54                                — `LoanCollateralPolicy`
 *                                                  (the authorization
 *                                                  surface)
 *   Phase 2C A.58                                — `LoanCollateralRepository`
 *                                                  (the read path)
 *   Phase 2C A.67                                — `StoreCollateralRequest`
 *   Phase 2C A.68                                — `ReleaseCollateralRequest`
 *   Phase 2C A.79                                — `LoanCollateralController`
 *                                                  (the sole HTTP
 *                                                  consumer)
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   CR-2C-COLLATERAL-PLEDGE-FK-001               — deferred pledge FK
 *                                                  (the CR that will
 *                                                  make the service's
 *                                                  existence check a
 *                                                  defence-in-depth
 *                                                  rather than the sole
 *                                                  referential-integrity
 *                                                  mechanism)
 *   Phase 2A 2A.8                                — `ShareHolding` (the
 *                                                  pledged-holding
 *                                                  model)
 *   Step 5 (manifest) A.37                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
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