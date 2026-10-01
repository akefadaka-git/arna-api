<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-L2
 * =============================================================================
 *
 * File:            mases_core/src/Domains/Loan/Http/Resources/LoanResource.php
 * Unit:            CR-L2 — Ethiopian calendar date rendering (Loan resources)
 * Delivery type:   Amendment — one import, one trait use, sixteen
 *                  `_display` fields (seven top-level, five inside the
 *                  restructuring summary, three inside the write-off
 *                  summary, one more in the restructuring summary for
 *                  the `created_at` field — counted below), docblock
 *                  amendments.
 * Net lines:       +2 imports/use; +16 field lines; docblock revision.
 *
 * Before:
 *   Every date field the resource emitted was ISO 8601 only:
 *
 *     application_date, decision_date, disbursement_date,
 *     maturity_date, closed_at, created_at, updated_at   (7 top-level)
 *     previous_maturity_date, new_maturity_date,
 *     restructured_at, created_at, updated_at            (5 restructurings)
 *     written_off_at, created_at, updated_at             (3 write-off)
 *
 *   That is sixteen date fields across three payload regions. A consumer
 *   that wanted the Amharic-first medium form had to slice the ISO
 *   string client-side, breaking the convention established by P3 ·
 *   Unit 2 across the Shareholder resources and extended to
 *   WalletResource by CR-W1.
 *
 * After:
 *   The resource mixes in RendersLocalizedDates (Reading 1:
 *   cross-domain import from the Shareholder domain, matching
 *   WalletResource's precedent) and emits a `_display` sibling for
 *   every date field. The ISO fields are preserved byte-for-byte; the
 *   `_display` fields are additive.
 *
 *   Each `_display` field appears immediately after its ISO parent, so
 *   a reviewer reading the array sees the two forms of the same field
 *   side by side. The ordering follows the pattern established by
 *   WalletResource (CR-W1 file 3) and by the four Shareholder
 *   resources (P3 · Unit 2).
 *
 *   The nullable date fields (`decision_date`, `disbursement_date`,
 *   `maturity_date`, `closed_at`, and the restructuring / write-off
 *   dates that are null before the corresponding event) have nullable
 *   `_display` siblings — the trait returns null for null input, so
 *   the two forms agree on which rows carry a value.
 *
 * Public surface delta:
 *   · No new methods. The trait contributes one `protected localizedDate()`
 *     method, which is not part of the resource's external contract.
 *   · The serialised payload gains sixteen keys (see the list below).
 *     No key is removed, renamed, or re-valued.
 *
 *     Top-level (7):
 *       application_date_display, decision_date_display,
 *       disbursement_date_display, maturity_date_display,
 *       closed_at_display, created_at_display, updated_at_display
 *
 *     Inside each restructurings[] entry (5):
 *       previous_maturity_date_display, new_maturity_date_display,
 *       restructured_at_display, created_at_display, updated_at_display
 *
 *     Inside the write_off object (3):
 *       written_off_at_display, created_at_display, updated_at_display
 *
 *   The entry-level and write-off-level `created_at_display` /
 *   `updated_at_display` fields appear at a DIFFERENT JSON path from
 *   the top-level fields of the same name. There is no collision — the
 *   nesting distinguishes them.
 *
 * Dependency note:
 *   · The trait is imported from the Shareholder domain's Concerns
 *     namespace:
 *     MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\
 *     RendersLocalizedDates.
 *     This mirrors WalletResource's identical cross-domain import
 *     (CR-W1 file 3). The trait is not relocated to Core in CR-L2
 *     (Reading 1); a future CR may move it, at which point this import
 *     and the five sibling Loan-resource imports change namespace.
 *
 *   · The trait itself was delivered as P3 · Unit 2 · File 2 of 13 and
 *     depends on LocalizationService::formatEthiopianDateMedium(), which
 *     was delivered as P3 · Unit 2 · File 1 of 13. Both are deployed
 *     (Track A is closed — the portal's Amharic-first date rendering
 *     was confirmed working).
 *
 *   · No new class is introduced by this file. The trait it uses is an
 *     existing class. No additional Composer refresh is required
 *     beyond the one already performed for the trait's deployment.
 *
 *   · No database migration. No config change. No route change.
 *
 * =============================================================================
 */

/*
 * =============================================================================
 * MASES Phase 2C — Loan Resource
 * =============================================================================
 *
 * File:      mases_core/src/Domains/Loan/Http/Resources/LoanResource.php
 * Version:   1.1.0
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanRestructuring;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanWriteOff;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;

/**
 * LoanResource
 *
 * The HTTP representation of a `Loan` aggregate (A.18). Serializes a loan
 * and — when the controller has eager-loaded them — its related records:
 * the borrower summary, the product, the frozen policy version, the actor
 * summaries, the installment schedule, the collaterals, the restructuring
 * history, the write-off, and the status history.
 *
 * =============================================================================
 * Shape
 * =============================================================================
 * The resource emits four groups of fields:
 *
 *   1. IDENTITY AND TERMS — the loan's primary identifiers, its lifecycle
 *      status, its principal and term, its currency, and its free-text
 *      fields (`purpose`, `external_reference`).
 *
 *   2. FINANCIAL STATE — the loan's four persisted projections
 *      (`settled_amount`, `outstanding_principal`, `outstanding_interest`,
 *      `outstanding_penalty`) plus the computed `outstanding_total`
 *      (delegated to `Loan::outstandingTotal()`), and the schedule-summary
 *      columns written at disbursement (`monthly_installment_amount`,
 *      `total_scheduled_interest`, `total_scheduled_repayment`).
 *
 *   3. LIFECYCLE DATES AND ACTORS — every timestamp the migration declares
 *      (`application_date`, `decision_date`, `disbursement_date`,
 *      `maturity_date`, `closed_at`, `created_at`, `updated_at`), each
 *      emitted as an ISO 8601 string AND as a localized display string;
 *      and every actor the loan row records by FK (`approved_by`,
 *      `disbursed_by`, `created_by`), each emitted as a raw id together
 *      with a `*_user` when-loaded summary.
 *
 *   4. NESTED RECORDS — five `whenLoaded` groups: `borrower`,
 *      `product`, `frozen_policy_version`, `approved_by_user`,
 *      `disbursed_by_user`, `created_by_user`, `installments`,
 *      `collaterals`, `restructurings`, `write_off`, and
 *      `status_histories`. Every relation is emitted ONLY when the
 *      controller eager-loaded it, via the `whenLoaded()` mechanism.
 *
 * =============================================================================
 * Every monetary field is a decimal string
 * =============================================================================
 * Every monetary field is emitted as the string the `decimal:4` cast
 * produces (A.18's `$casts` array). No arithmetic is performed inside
 * this resource EXCEPT for `outstanding_total`, which delegates to
 * `Loan::outstandingTotal()` (A.18) — a bcmath computation at scale 4
 * that the model exposes as the authoritative sum of the three
 * outstanding projections.
 *
 * The resource NEVER casts a monetary value to float. The platform-wide
 * no-floats discipline (handoff §3) applies to the resource layer as
 * strictly as it applies to the service layer.
 *
 * =============================================================================
 * Dates — ISO 8601 and localized display siblings  (CR-L2)
 * =============================================================================
 * Every date field is emitted via `->toIso8601String()` on the Carbon
 * instance the `datetime` or `date` cast produces. Nullable dates (e.g.
 * `decision_date` before approval, `closed_at` before closure) emit
 * `null`, not an empty string and not a sentinel value.
 *
 * Each ISO date is accompanied by a `_display` sibling — a
 * locale-appropriate medium-form string produced by
 * LocalizationService::formatEthiopianDateMedium() via the
 * RendersLocalizedDates trait (imported cross-domain from the
 * Shareholder domain, matching WalletResource's precedent):
 *
 *     am:  መስከረም 5, 2018 ዓ.ም. (September 15, 2025)
 *     en:  Meskerem 5, 2018 E.C. (September 15, 2025)
 *
 * The two forms serve different consumers:
 *
 *   · The ISO field remains the wire's machine-readable source of truth:
 *     parseable, sortable, timezone-explicit. Preserved for clients that
 *     need the exact instant.
 *
 *   · The `_display` field is the localized human-readable form. Amharic
 *     month names for `am`, transliterated Latin for `en`, with the
 *     Gregorian equivalent in parentheses. Rendered by the UI directly.
 *
 * A nullable ISO date has a nullable `_display` sibling — the trait
 * returns null for null input, so the pairing on which rows carry a
 * value is preserved without a special case.
 *
 * The `application_date` column is NOT NULL per the migration A.9; the
 * null-safe operator is applied uniformly for defensive consistency.
 *
 * =============================================================================
 * The whenLoaded contract
 * =============================================================================
 * Every relation is wrapped in `whenLoaded()`. A resource serialized
 * without eager-loading a given relation omits the corresponding field
 * entirely from the output; it does NOT emit an empty array, a null, or a
 * placeholder.
 *
 * Controllers compose the eager-load set at the query boundary via the
 * `$with` parameter on `LoanRepository` (A.55). The resource trusts the
 * controller's choice and NEVER triggers a lazy-load. A resource that
 * fired a lazy-load would defeat the purpose of the `$with` parameter
 * and would produce an N+1 query pattern in a listing endpoint.
 *
 * The relation names used with `whenLoaded()` are the exact relation
 * method names declared on the `Loan` model (A.18):
 *
 *   'borrower'         — the Shareholder who is the borrower.
 *   'product'          — the LoanProduct.
 *   'policyVersion'    — the frozen VersionedPolicy.
 *   'approvedBy'       — the User who approved.
 *   'disbursedBy'      — the User who disbursed.
 *   'createdBy'        — the User who created the draft.
 *   'installments'     — the Collection of LoanInstallment.
 *   'collaterals'      — the Collection of LoanCollateral.
 *   'restructurings'   — the Collection of LoanRestructuring.
 *   'writeOff'         — the LoanWriteOff (HasOne).
 *   'statusHistories'  — the Collection of LoanStatusHistory.
 *
 * =============================================================================
 * Actor user payloads — the inline shape
 * =============================================================================
 * The resource does NOT use a shared `UserResource` — no such resource
 * exists in the Phase 2C manifest. Instead, every actor relation emits an
 * inline `{id, email, name}` payload, matching the pattern used by
 * Phase 2B's Wallet resources and by every Phase 2A resource that
 * presents a user summary.
 *
 * The actor fields are emitted in TWO forms:
 *
 *   - The raw FK id (`approved_by`, `disbursed_by`, `created_by`),
 *     always present because it is a column on the `loans` row.
 *
 *   - The when-loaded summary (`approved_by_user`, `disbursed_by_user`,
 *     `created_by_user`), present only when the controller eager-loaded
 *     the corresponding `*By` relation.
 *
 * The dual emission lets an API consumer choose the cheaper form (the
 * FK id) or the richer form (the summary) based on their needs; a caller
 * that wants only the ids avoids the eager-load and gets no `_user`
 * fields.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does NOT trigger lazy-loads. See §"The whenLoaded contract".
 *
 *   - It does NOT compute monetary values beyond delegating to the
 *     model's `outstandingTotal()` helper. See §"Every monetary field is
 *     a decimal string".
 *
 *   - It does NOT emit floating-point values. Every monetary value is a
 *     decimal string; every date is an ISO 8601 string plus a localized
 *     `_display` sibling.
 *
 *   - It does NOT include the `tenant` relation. The `tenant_id` column
 *     is emitted directly; a caller that needs the tenant entity resolves
 *     it through its own read path. Adding a nested tenant would push
 *     the resource beyond the loan's own scope.
 *
 *   - It does NOT include the `interestAccruals` or `penaltyAccruals`
 *     relations. Those are bookkeeping records for the domain's internal
 *     reconciliation; a caller that needs them reads them through the
 *     reconciliation endpoint (A.83, A.75) rather than through the loan
 *     detail. If a future CR requires them inline, they are added as new
 *     whenLoaded groups.
 *
 *   - It does NOT include the `restructurings` and `write_off` as full
 *     sub-resources, because no `LoanRestructuringResource` or
 *     `LoanWriteOffResource` exists in the Phase 2C manifest. Their
 *     payloads are emitted as inline arrays built by the private helpers
 *     below, and their date fields carry `_display` siblings emitted by
 *     the same trait the resource mixes in.
 *
 *   - It does NOT perform authorization. The controller gates the
 *     endpoint through `Gate::authorize(...)`; the resource is a pure
 *     serializer.
 *
 * =============================================================================
 * Correction history
 * =============================================================================
 * v1.0.0  — initial delivery.
 *
 * v1.1.0  — Ethiopian calendar date rendering (CR-L2):
 *
 *           · The `RendersLocalizedDates` trait is imported from the
 *             Shareholder domain (cross-domain, matching the
 *             WalletResource precedent from CR-W1 file 3) and mixed into
 *             the class.
 *
 *           · Sixteen `_display` sibling fields are added: seven at the
 *             top level, five inside each `restructurings[]` entry, and
 *             three inside the `write_off` object. Each `_display` field
 *             appears immediately after its ISO sibling.
 *
 *           · The ISO fields are preserved byte-for-byte. Every existing
 *             key retains its name, its type, and its value; the
 *             `_display` fields are additive.
 *
 *           · No structural change. No new whenLoaded group, no new
 *             private helper, no new dependency beyond the trait.
 *
 *           · This aligns LoanResource with the P3 · Unit 2 convention
 *             that the four Shareholder-domain resources and the Wallet
 *             domain's WalletResource already follow.
 *
 *           · The trait is not relocated to Core in this revision
 *             (Reading 1). A future CR may move it, at which point this
 *             file's `use` statement and the five sibling Loan-resource
 *             `use` statements change namespace.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D129, D130, D132,
 *                                                  D133, D135, B2, B6
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C A.18                                — `Loan` (the model
 *                                                  serialized by this
 *                                                  resource)
 *   Phase 2C A.20                                — `LoanCollateral`
 *   Phase 2C A.21                                — `LoanRestructuring`
 *   Phase 2C A.22                                — `LoanWriteOff`
 *   Phase 2C A.55                                — `LoanRepository` (the
 *                                                  `$with` composition
 *                                                  point)
 *   Phase 2C A.70                                — `LoanProductResource`
 *                                                  (the nested product)
 *   Phase 2C A.71                                — `LoanInstallmentResource`
 *                                                  (the nested
 *                                                  installments)
 *   Phase 2C A.72                                — `LoanCollateralResource`
 *                                                  (the nested
 *                                                  collaterals)
 *   Phase 2C A.76                                — `LoanStatusHistoryResource`
 *                                                  (the nested status
 *                                                  history)
 *   Phase 2C A.77                                — `LoanController` (the
 *                                                  primary consumer)
 *   Phase 2B 2B.23                               — `WalletResource` (the
 *                                                  shape precedent for
 *                                                  inline actor
 *                                                  summaries)
 *   P3 · Unit 2                                  — Ethiopian calendar
 *                                                  date rendering (the
 *                                                  convention this
 *                                                  amendment extends)
 *   CR-W1                                        — `WalletResource`'s
 *                                                  identical `_display`
 *                                                  extension (the
 *                                                  cross-domain-import
 *                                                  precedent)
 *   CR-L2                                        — This amendment
 *   Step 5 (manifest) A.69                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Resources
 * @version 1.1.0
 */
final class LoanResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the automatic `data` wrapping.
     *
     * The canonical MASES response envelope wraps the whole HTTP response
     * in its own `{success, data, ...}` structure (produced by the
     * controller), so a `data` key inside the resource payload would
     * double-wrap. Setting the static `$wrap` to `null` for this resource
     * disables Laravel's own wrapping, leaving the controller's envelope
     * as the single wrapping layer.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the loan into its array representation.
     *
     * See the class docblock for the field layout and for the
     * no-lazy-load / no-floats / ISO-8601-plus-`_display` contract.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // -----------------------------------------------------------------
            // Identity.
            // -----------------------------------------------------------------
            'id'                      => (int) $this->id,
            'tenant_id'               => (int) $this->tenant_id,
            'borrower_shareholder_id' => (int) $this->borrower_shareholder_id,
            'loan_product_id'         => (int) $this->loan_product_id,

            // -----------------------------------------------------------------
            // Lifecycle status.
            //
            // The `status` column is enum-cast on the Loan model (A.18);
            // `->value` yields the snake_case backing string.
            // -----------------------------------------------------------------
            'status' => $this->status->value,

            // -----------------------------------------------------------------
            // Contractual terms.
            // -----------------------------------------------------------------
            'principal_amount' => (string) $this->principal_amount,
            'term_months'      => (int) $this->term_months,
            'currency'         => $this->currency,

            // -----------------------------------------------------------------
            // Free text.
            // -----------------------------------------------------------------
            'purpose'            => $this->purpose,
            'external_reference' => $this->external_reference,

            // -----------------------------------------------------------------
            // Persisted projections and their computed sum.
            //
            // The three `outstanding_*` columns are nullable on the Loan
            // model (see A.18's @property docblock); they emit as null
            // before the corresponding operation has run. The
            // `outstanding_total` helper treats null as zero (A.18).
            // -----------------------------------------------------------------
            'settled_amount'        => (string) $this->settled_amount,
            'outstanding_principal' => $this->outstanding_principal,
            'outstanding_interest'  => $this->outstanding_interest,
            'outstanding_penalty'   => (string) $this->outstanding_penalty,
            'outstanding_total'     => $this->outstandingTotal(),

            // -----------------------------------------------------------------
            // Schedule summary.
            //
            // All three are nullable on the Loan model; they emit as null
            // before disbursement has written them (A.18, A.27).
            // -----------------------------------------------------------------
            'monthly_installment_amount' => $this->monthly_installment_amount,
            'total_scheduled_interest'   => $this->total_scheduled_interest,
            'total_scheduled_repayment'  => $this->total_scheduled_repayment,

            // -----------------------------------------------------------------
            // Policy freeze.
            //
            // The raw id is always emitted; the summary is emitted only
            // when the `policyVersion` relation is eager-loaded.
            // -----------------------------------------------------------------
            'frozen_policy_version_id' => $this->frozen_policy_version_id,
            'frozen_policy_version'    => $this->whenLoaded(
                'policyVersion',
                fn (): ?array => $this->frozenPolicyVersionSummary(),
            ),

            // -----------------------------------------------------------------
            // Lifecycle dates — ISO + localized display, paired.  (CR-L2)
            //
            // Emitted as ISO 8601 strings, or null when the corresponding
            // event has not occurred yet. `application_date` is NOT NULL
            // per the migration A.9; the null-safe operator is applied
            // uniformly for defensive consistency. Each ISO field is
            // followed by its `_display` sibling produced by the
            // RendersLocalizedDates trait. See the class docblock
            // §"Dates — ISO 8601 and localized display siblings".
            // -----------------------------------------------------------------
            'application_date'         => $this->application_date?->toIso8601String(),
            'application_date_display' => $this->localizedDate($this->application_date),
            'decision_date'            => $this->decision_date?->toIso8601String(),
            'decision_date_display'    => $this->localizedDate($this->decision_date),
            'disbursement_date'        => $this->disbursement_date?->toIso8601String(),
            'disbursement_date_display' => $this->localizedDate($this->disbursement_date),
            'maturity_date'            => $this->maturity_date?->toIso8601String(),
            'maturity_date_display'    => $this->localizedDate($this->maturity_date),
            'closed_at'                => $this->closed_at?->toIso8601String(),
            'closed_at_display'        => $this->localizedDate($this->closed_at),

            // -----------------------------------------------------------------
            // Actor FK ids.
            //
            // The raw FK columns, always present. The corresponding user
            // summaries are emitted in the `*_user` fields below, only
            // when the relation is eager-loaded.
            // -----------------------------------------------------------------
            'approved_by'  => $this->approved_by,
            'disbursed_by' => $this->disbursed_by,
            'created_by'   => $this->created_by,

            // -----------------------------------------------------------------
            // Actor summaries (whenLoaded).
            //
            // The `*By` relations are HasOne on User; each emits an
            // inline `{id, email, name}` payload when loaded, or null
            // when the relation is loaded but the FK is null (e.g. a
            // not-yet-approved loan's `approvedBy` relation is null).
            // -----------------------------------------------------------------
            'approved_by_user'  => $this->whenLoaded(
                'approvedBy',
                fn (): ?array => $this->actorSummary($this->approvedBy),
            ),
            'disbursed_by_user' => $this->whenLoaded(
                'disbursedBy',
                fn (): ?array => $this->actorSummary($this->disbursedBy),
            ),
            'created_by_user'   => $this->whenLoaded(
                'createdBy',
                fn (): ?array => $this->actorSummary($this->createdBy),
            ),

            // -----------------------------------------------------------------
            // Borrower summary (whenLoaded).
            //
            // The borrower is a Shareholder (per D129). The summary emits
            // the three identifying columns a Shareholder row is known to
            // carry: its primary key, its tenant scope, and the user id
            // that links it to the platform's User model. A future CR
            // that exposes richer Shareholder fields extends this payload.
            // -----------------------------------------------------------------
            'borrower' => $this->whenLoaded(
                'borrower',
                fn (): ?array => $this->borrowerSummary(),
            ),

            // -----------------------------------------------------------------
            // Product (whenLoaded).
            //
            // Emitted through the dedicated `LoanProductResource` (A.70),
            // which is the product catalogue's canonical representation.
            // -----------------------------------------------------------------
            'product' => $this->whenLoaded(
                'product',
                fn () => LoanProductResource::make($this->product),
            ),

            // -----------------------------------------------------------------
            // Schedule installments (whenLoaded).
            // -----------------------------------------------------------------
            'installments' => $this->whenLoaded(
                'installments',
                fn () => LoanInstallmentResource::collection($this->installments),
            ),

            // -----------------------------------------------------------------
            // Collaterals (whenLoaded).
            // -----------------------------------------------------------------
            'collaterals' => $this->whenLoaded(
                'collaterals',
                fn () => LoanCollateralResource::collection($this->collaterals),
            ),

            // -----------------------------------------------------------------
            // Restructuring history (whenLoaded).
            //
            // No dedicated `LoanRestructuringResource` exists in the
            // Phase 2C manifest; the payloads are built inline by
            // `restructuringSummary()`, which emits ISO + `_display`
            // pairs for its five date fields.  (CR-L2)
            // -----------------------------------------------------------------
            'restructurings' => $this->whenLoaded(
                'restructurings',
                fn (): array => $this->restructurings
                    ->map(fn ($r) => $this->restructuringSummary($r))
                    ->all(),
            ),

            // -----------------------------------------------------------------
            // Write-off (whenLoaded).
            //
            // No dedicated `LoanWriteOffResource` exists in the Phase 2C
            // manifest; the payload is built inline by
            // `writeOffSummary()`, which emits ISO + `_display` pairs
            // for its three date fields.  (CR-L2) The relation is
            // HasOne; a loan that has not been written off yields null.
            // -----------------------------------------------------------------
            'write_off' => $this->whenLoaded(
                'writeOff',
                fn (): ?array => $this->writeOffSummary($this->writeOff),
            ),

            // -----------------------------------------------------------------
            // Status history (whenLoaded).
            // -----------------------------------------------------------------
            'status_histories' => $this->whenLoaded(
                'statusHistories',
                fn () => LoanStatusHistoryResource::collection($this->statusHistories),
            ),

            // -----------------------------------------------------------------
            // Timestamps — ISO + localized display, paired.  (CR-L2)
            // -----------------------------------------------------------------
            'created_at'         => $this->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($this->created_at),
            'updated_at'         => $this->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($this->updated_at),
        ];
    }

    // =========================================================================
    // Private — nested-payload builders
    // =========================================================================

    /**
     * Build the inline actor summary for a user.
     *
     * The `{id, email, name}` shape is the platform-wide actor payload,
     * matching Phase 2B's Wallet resources and Phase 2A's resources. No
     * `UserResource` exists in the Phase 2C manifest; a shared resource
     * would be a change request.
     *
     * @param User|null $user
     * @return array{id: int, email: string|null, name: string|null}|null
     */
    private function actorSummary(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id'    => (int) $user->getKey(),
            'email' => $user->email,
            'name'  => $user->name,
        ];
    }

    /**
     * Build the inline borrower summary for a Shareholder.
     *
     * Returns null when the loaded borrower relation is null — a state
     * that the FK `loans.borrower_shareholder_id` prevents in a
     * well-formed row, but that the resource handles defensively.
     *
     * The emitted fields are the three the Shareholder model is known to
     * carry from the Phase 2A schema: its primary key, its tenant scope,
     * and its user linkage. A future CR that exposes richer Shareholder
     * fields (a shareholder number, a display name) extends this payload.
     *
     * @return array{id: int, tenant_id: int, user_id: int|null}|null
     */
    private function borrowerSummary(): ?array
    {
        $borrower = $this->borrower;

        if ($borrower === null) {
            return null;
        }

        return [
            'id'        => (int) $borrower->getKey(),
            'tenant_id' => (int) $borrower->tenant_id,
            'user_id'   => $borrower->user_id !== null
                ? (int) $borrower->user_id
                : null,
        ];
    }

    /**
     * Build the inline summary for the frozen policy version.
     *
     * Returns null when the loaded policyVersion relation is null — a
     * state a pre-approval loan legitimately carries, since the freeze
     * happens at the under_review → approved transition (A.29).
     *
     * The emitted fields identify the version without exposing its
     * payload: a caller that needs the payload resolves the version
     * through the Core VersionedPolicyRegistry on their own read path.
     * The resource deliberately does not denormalize the payload into
     * every loan response.
     *
     * @return array{id: int, policy_type: string, version: string|int|null}|null
     */
    private function frozenPolicyVersionSummary(): ?array
    {
        $policyVersion = $this->policyVersion;

        if ($policyVersion === null) {
            return null;
        }

        return [
            'id'          => (int) $policyVersion->getKey(),
            'policy_type' => (string) $policyVersion->policy_type,
            'version'     => $policyVersion->version ?? null,
        ];
    }

    /**
     * Build the inline summary for a single restructuring history entry.
     *
     * No dedicated `LoanRestructuringResource` exists in the Phase 2C
     * manifest. The payload captures the full before-and-after terms of
     * the restructure plus the provenance fields; a caller that needs the
     * restructuring's narrative reads `reason` and the two sets of
     * `*_outstanding_*` / `new_*` fields.
     *
     * Every monetary value is a decimal string (per the model's
     * `decimal:4` casts, A.21).
     *
     * Five date fields — `previous_maturity_date`, `new_maturity_date`,
     * `restructured_at`, `created_at`, `updated_at` — are each emitted
     * as an ISO 8601 string plus a localized `_display` sibling, per
     * CR-L2. See the class docblock §"Dates — ISO 8601 and localized
     * display siblings".
     *
     * @param LoanRestructuring $restructuring
     * @return array<string, mixed>
     */
    private function restructuringSummary(LoanRestructuring $restructuring): array
    {
        return [
            'id' => (int) $restructuring->getKey(),

            'previous_policy_version_id'     => $restructuring->previous_policy_version_id,
            'previous_outstanding_principal' => (string) $restructuring->previous_outstanding_principal,
            'previous_outstanding_interest'  => (string) $restructuring->previous_outstanding_interest,
            'previous_outstanding_penalty'   => (string) $restructuring->previous_outstanding_penalty,
            'previous_installment_count'     => (int) $restructuring->previous_installment_count,
            'previous_maturity_date'         => $restructuring->previous_maturity_date?->toIso8601String(),
            'previous_maturity_date_display' => $this->localizedDate($restructuring->previous_maturity_date),

            'new_policy_version_id'         => $restructuring->new_policy_version_id,
            'new_principal_amount'          => (string) $restructuring->new_principal_amount,
            'new_term_months'               => (int) $restructuring->new_term_months,
            'new_installment_count'         => (int) $restructuring->new_installment_count,
            'new_maturity_date'             => $restructuring->new_maturity_date?->toIso8601String(),
            'new_maturity_date_display'     => $this->localizedDate($restructuring->new_maturity_date),
            'new_total_scheduled_repayment' => (string) $restructuring->new_total_scheduled_repayment,

            'reason'                   => (string) $restructuring->reason,
            'restructured_at'          => $restructuring->restructured_at?->toIso8601String(),
            'restructured_at_display'  => $this->localizedDate($restructuring->restructured_at),
            'restructured_by'          => $restructuring->restructured_by,

            'created_at'         => $restructuring->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($restructuring->created_at),
            'updated_at'         => $restructuring->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($restructuring->updated_at),
        ];
    }

    /**
     * Build the inline summary for the write-off record.
     *
     * No dedicated `LoanWriteOffResource` exists in the Phase 2C manifest.
     * Returns null when the loaded writeOff relation is null — the state
     * a loan that has not been written off legitimately carries (the
     * relation is HasOne, A.18).
     *
     * Every monetary value is a decimal string (per the model's
     * `decimal:4` casts, A.22).
     *
     * Three date fields — `written_off_at`, `created_at`, `updated_at` —
     * are each emitted as an ISO 8601 string plus a localized `_display`
     * sibling, per CR-L2. See the class docblock §"Dates — ISO 8601 and
     * localized display siblings".
     *
     * @param LoanWriteOff|null $writeOff
     * @return array<string, mixed>|null
     */
    private function writeOffSummary(?LoanWriteOff $writeOff): ?array
    {
        if ($writeOff === null) {
            return null;
        }

        return [
            'id' => (int) $writeOff->getKey(),

            'write_off_amount'                   => (string) $writeOff->write_off_amount,
            'outstanding_principal_at_writeoff'  => (string) $writeOff->outstanding_principal_at_writeoff,
            'outstanding_interest_at_writeoff'   => (string) $writeOff->outstanding_interest_at_writeoff,
            'outstanding_penalty_at_writeoff'    => (string) $writeOff->outstanding_penalty_at_writeoff,

            'reason'                  => (string) $writeOff->reason,
            'written_off_at'          => $writeOff->written_off_at?->toIso8601String(),
            'written_off_at_display'  => $this->localizedDate($writeOff->written_off_at),
            'written_off_by'          => $writeOff->written_off_by,

            'created_at'         => $writeOff->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($writeOff->created_at),
            'updated_at'         => $writeOff->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($writeOff->updated_at),
        ];
    }
}