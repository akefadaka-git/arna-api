<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-W1
 * =============================================================================
 *
 * File:            WalletResource.php  (AMEND)
 * Unit:            CR-W1 — Portal self-service wallet endpoint
 * Delivery type:   Amendment — one import, one trait use, four new
 *                  `_display` fields, docblock revised.
 * Net lines:       +2 imports/use, +4 field lines, docblock revision.
 *
 * Before:
 *   WalletResource emitted its four date fields — opened_at, closed_at,
 *   created_at, updated_at — as raw ISO 8601 strings only. There were
 *   no `_display` siblings. A consumer that wanted the Amharic-first
 *   medium form had to slice the ISO string client-side, breaking the
 *   convention established by P3 · Unit 2 across the four Shareholder
 *   resources.
 *
 *   CR-W2 (MyWalletPage.vue) will render wallet dates and needs the
 *   localised medium form. Without this amendment it would either render
 *   raw ISO (inconsistent with every other portal page) or ship a
 *   client-side formatter (the convention P3 · Unit 2 deliberately
 *   retired).
 *
 * After:
 *   WalletResource mixes in RendersLocalizedDates and emits a
 *   `_display` sibling for each of its four date fields. The ISO fields
 *   are preserved byte-for-byte; the `_display` fields are additive.
 *
 *   Field ordering follows the trait's documented convention: each
 *   `_display` sibling is emitted immediately after its ISO parent, so
 *   a reviewer reading the array sees the two forms of the same field
 *   side by side.
 *
 *   The four `_display` values are:
 *
 *     opened_at_display   — the localised medium form of opened_at
 *     closed_at_display   — the localised medium form of closed_at (null
 *                           when closed_at is null)
 *     created_at_display  — the localised medium form of created_at
 *     updated_at_display  — the localised medium form of updated_at
 *
 * Public surface delta:
 *   · No new methods on WalletResource. The trait contributes one
 *     `protected localizedDate()` method, which is not part of the
 *     resource's external contract.
 *   · The serialised payload gains four keys (see above). No key is
 *     removed or renamed.
 *
 * Files modified:            this file only
 * Files deleted:             none
 * Routes registered:         none
 * Policies modified:         none
 * Other resources modified:  none
 * Migrations:                none
 * Seeders:                   none
 *
 * Dependency note:
 *   · The trait is imported from the Shareholder domain's Concerns
 *     namespace: MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\
 *     Concerns\RendersLocalizedDates. WalletResource ALREADY imports
 *     MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\
 *     ShareholderResource, so this is a further use of an existing
 *     cross-domain dependency, not a new one.
 *
 *   · The trait itself was delivered as P3 · Unit 2 · File 2 of 13 and
 *     depends on LocalizationService::formatEthiopianDateMedium(), which
 *     was delivered as P3 · Unit 2 · File 1 of 13. Both are on the
 *     outstanding deployment list (Track A). Until they are uploaded and
 *     the Composer autoloader is refreshed, this amended resource will
 *     fail to load.
 *
 *   · This file's deployment therefore MUST follow the deployment of the
 *     RendersLocalizedDates trait and a successful `composer dump-autoload`.
 *     It is not independently deployable in the sense that file 1 and
 *     file 2 of CR-W1 were.
 *
 *   · No new class is introduced by this file. The trait it uses is an
 *     existing class (once deployed). No further Composer refresh is
 *     required beyond the one that deploys the trait itself.
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\ShareholderResource;

/**
 * WalletResource
 *
 * Serializes a `Wallet` model into the API's wallet payload.
 *
 * =============================================================================
 * Envelope responsibility
 * =============================================================================
 * `$wrap = null` — the resource does NOT wrap its output in a `data` key.
 * The controller is the sole envelope provider (Architecture §6.2 of the
 * handoff conventions), and it produces:
 *
 *     { "success": true, "data": <this resource's array> }
 *
 * A controller that returns `WalletResource::collection($wallets)`
 * produces a `data` key containing an array of the per-wallet payloads.
 * The resource itself never emits `success` or `data`.
 *
 * =============================================================================
 * Field order (Architecture conventions §2.7)
 * =============================================================================
 * Fields are emitted in a fixed order:
 *
 *   1. Identity       — id, tenant_id, shareholder_id
 *   2. Currency       — currency
 *   3. Balance        — current_balance
 *   4. Lifecycle      — is_active, is_closed, opened_at, opened_at_display,
 *                       closed_at, closed_at_display
 *   5. Policy slots   — interest_policy_version_id,
 *                       allocation_policy_version_id,
 *                       kyc_policy_version_id
 *   6. Embedded rels  — shareholder, obligations, payments
 *                       (only when loaded — see §"Conditional relations")
 *   7. Timestamps     — created_at, created_at_display,
 *                       updated_at, updated_at_display
 *
 * The order is presentation-oriented. JSON objects are unordered in the
 * specification, but keeping a stable order in the source makes the
 * payload easy to review and diff.
 *
 * =============================================================================
 * Ethiopian-calendar display siblings  (CR-W1)
 * =============================================================================
 * Every date field carries a `_display` sibling that renders the same
 * instant in the localised medium Ethiopian form:
 *
 *     am:  ሐምሌ 9, 2018 ዓ.ም. (July 16, 2026)
 *     en:  Hamle 9, 2018 E.C. (July 16, 2026)
 *
 * The ISO field remains the wire's machine-readable source of truth; the
 * `_display` field is the human-readable presentation. Both are derived
 * server-side from the same Carbon instance, so they cannot disagree. A
 * client renders `_display` directly; it does not parse the ISO field and
 * it does not know the Ethiopian calendar.
 *
 * The sibling is produced by the `RendersLocalizedDates` trait, imported
 * from the Shareholder domain (see that trait's class docblock for the
 * locale and timezone derivation). The `closed_at_display` sibling is
 * null when `closed_at` is null — the trait returns null for null input,
 * so the pairing of the two fields on which rows carry a value is
 * preserved without a special case.
 *
 * This mirrors the P3 · Unit 2 convention already applied to
 * ShareholderResource, ShareHoldingResource, ShareCertificateResource,
 * and ShareClassResource. See that unit's scoping document.
 *
 * =============================================================================
 * Conditional relations (Step 7 v1.1.0 §A.4, F-2B-LIST-1)
 * =============================================================================
 * The resource embeds the wallet's `shareholder`, `obligations`, and
 * `payments` relations ONLY when the caller has eager-loaded them. The
 * mechanism is `whenLoaded()`, which:
 *
 *   - Returns the nested resource when the relation is present on the
 *     model.
 *   - Omits the key entirely when the relation is NOT loaded.
 *
 * The key is OMITTED, not set to null or an empty array. A caller that
 * receives a payload without the `obligations` key knows the relation
 * was not loaded — the omission is the signal. A caller that receives
 * `obligations: []` knows the relation was loaded and is empty.
 *
 * This is the F-2B-LIST-1 resolution: obligations and payments are not
 * separate endpoints; they are embedded under the wallet when the caller
 * loads them. The 2B controllers (2B.26–2B.30) do NOT eager-load them on
 * any endpoint in the manifest — a caller that needs them issues a
 * future-CR query parameter. But the resource is written to expose them
 * correctly the moment a caller does load them, whether that caller is a
 * future controller, a test, or an artisan script.
 *
 * The `entries` (ledger) relation is NOT exposed by this resource. The
 * ledger is a large, ordered, append-only stream that is not appropriate
 * for embedding under the wallet's primary payload; it is surfaced
 * through the statement endpoint (2B.30) and through its own future
 * endpoint if one is added. This mirrors the 2A decision not to embed
 * `share_holding_entries` under `ShareHoldingResource`.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * Every value emitted by this resource comes from a property or a
 * relation already present on the model. There are no `Model::find(...)`
 * calls, no `->load(...)` calls, and no lazy-loaded relation accesses.
 * A caller that wants a nested relation present must load it before
 * passing the model to the resource — a pattern enforced by the
 * `whenLoaded()` guards on every relation field.
 *
 * The `_display` siblings call `LocalizationService::formatEthiopianDateMedium()`,
 * which performs no database query — the Ethiopian-calendar arithmetic is
 * pure computation over the given instant. The resource's "no database
 * queries" property therefore holds with the `_display` siblings in place.
 *
 * =============================================================================
 * Formatting choices
 * =============================================================================
 *   - `current_balance` is emitted as the string from the model's
 *     `decimal:4` cast. No numeric formatting is applied by the resource;
 *     consumer presentation (thousands separators, currency symbol,
 *     locale-specific decimal mark) is a UI concern.
 *
 *   - `opened_at`, `closed_at`, `created_at`, and `updated_at` are
 *     emitted as ISO 8601 strings via `->toIso8601String()`. This
 *     matches the convention established by the statement service
 *     (2B.19 §"generate") and by the 2A resources.
 *
 *   - Their `_display` siblings are emitted as localised medium-form
 *     strings (or null where the ISO sibling is null) via the trait's
 *     `localizedDate()` method. The format is owned by
 *     LocalizationService; the resource does not reformat.
 *
 *   - `currency` is emitted as its stored string (`'ETB'`, `'USD'`, …).
 *     No allow-list normalisation is applied.
 *
 *   - `is_active` and `is_closed` are booleans. `is_closed` is derived
 *     from `closed_at` (2B.5 §"isClosed"); it is emitted as a derived
 *     value so that consumers do not have to test `closed_at !== null`
 *     themselves. The derivation is performed by calling the model's
 *     `isClosed()` METHOD — not by reading an `is_closed` attribute,
 *     which does not exist on the model.
 *
 *   - The three policy-version references are emitted as integers or
 *     null. The full policy payloads are NOT embedded: policies are
 *     cross-tenant reference data (Architecture §8) and, in 2B, are
 *     "slot only" — no consumer evaluates them. A caller that needs the
 *     policy reads it through the registry (a future endpoint).
 *
 * =============================================================================
 * Correction history
 * =============================================================================
 * v1.0.1:
 *   Two defects from v1.0.0 corrected:
 *
 *     1. Missing `use` statements for `ShareholderResource`,
 *        `ObligationResource`, and `PaymentResource`. The relation
 *        closures in v1.0.0 referenced these classes by short name
 *        without importing them, causing
 *        `Error: Class "...\Resources\ShareholderResource" not found`
 *        whenever the corresponding relation was loaded. The classes
 *        live in three different namespaces (Shareholder domain, and
 *        this namespace for the two Wallet resources), so each requires
 *        its own `use` statement.
 *
 *     2. `is_closed` read `$this->is_closed` as an attribute. The
 *        `Wallet` model does not carry an `is_closed` column; it exposes
 *        a `isClosed(): bool` METHOD (2B.5 §"Domain helpers"). The
 *        attribute access returned `null`, so the serialized payload
 *        contained `"is_closed": null` instead of `true` or `false`. The
 *        corrected resource calls the method.
 *
 *   No other line was changed.
 *
 * v1.1.0  (CR-W1):
 *   Adds Ethiopian-calendar display siblings for the four date fields,
 *   via the `RendersLocalizedDates` trait imported from the Shareholder
 *   domain. Purely additive: no existing field is removed, renamed, or
 *   re-valued. The resource's `toArray()` grows four keys; the trait adds
 *   one protected method that is not part of the external contract.
 *
 *   This aligns WalletResource with the P3 · Unit 2 convention that the
 *   four Shareholder-domain resources already follow. See that unit's
 *   scoping document and the trait's own class docblock for the locale
 *   and timezone derivation.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §8.5    — Wallet policy references
 *   Architecture v1.2.0 §11     — Statement projection boundary
 *                                 (ledger NOT embedded here)
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §19     — Q8 resolution (currency)
 *   Step 5 (manifest) 2B.31     — This file
 *   Step 7 v1.1.0 §A.4          — F-2B-LIST-1 resolution (whenLoaded)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure
 *   P3 · Unit 2                 — Ethiopian calendar date rendering
 *                                 (the convention this amendment extends)
 *   CR-W1                       — This amendment
 *   §2.7 (conventions)          — Resource conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Resources
 * @version 1.1.0
 */
final class WalletResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the resource's default `data` wrapping.
     *
     * The controller provides the envelope (`{ success, data }`). See the
     * class docblock §"Envelope responsibility".
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * The `$request` parameter is required by the `JsonResource` contract
     * but is not used by this resource — no input-dependent fields exist
     * on the wallet payload. It is retained for interface compliance and
     * for the day a request-aware field (e.g. a `can` block) is added by
     * a future CR.
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
            'id'             => (int) $this->id,
            'tenant_id'      => (int) $this->tenant_id,
            'shareholder_id' => (int) $this->shareholder_id,

            // -----------------------------------------------------------------
            // Currency (Q8) — immutable after creation.
            // -----------------------------------------------------------------
            'currency' => (string) $this->currency,

            // -----------------------------------------------------------------
            // Balance projection (Architecture §4.6).
            //
            // The value is the string from the model's `decimal:4` cast.
            // No formatting is applied by the resource.
            // -----------------------------------------------------------------
            'current_balance' => (string) $this->current_balance,

            // -----------------------------------------------------------------
            // Lifecycle flags.
            //
            // `is_closed` is derived by calling the model's `isClosed()`
            // METHOD (2B.5 §"Domain helpers"). The method returns
            // `$this->closed_at !== null`. Reading `$this->is_closed` as
            // an attribute would return null — the model has no such
            // column. See the class docblock §"Correction history".
            // -----------------------------------------------------------------
            'is_active' => (bool) $this->is_active,
            'is_closed' => $this->isClosed(),

            // -----------------------------------------------------------------
            // Business timestamps.
            //
            // Each ISO field is followed by its `_display` sibling, which
            // renders the same instant in the localised medium Ethiopian
            // form via the RendersLocalizedDates trait. `closed_at_display`
            // is null when `closed_at` is null — the trait returns null
            // for null input, so the pairing on which rows carry a value
            // is preserved. See the class docblock §"Ethiopian-calendar
            // display siblings" (CR-W1).
            // -----------------------------------------------------------------
            'opened_at'         => $this->opened_at?->toIso8601String(),
            'opened_at_display' => $this->localizedDate($this->opened_at),
            'closed_at'         => $this->closed_at?->toIso8601String(),
            'closed_at_display' => $this->localizedDate($this->closed_at),

            // -----------------------------------------------------------------
            // Policy slots (A7, A8, D110).
            //
            // Nullable integer ids. The full policy payloads are NOT
            // embedded; see the class docblock §"Formatting choices".
            //
            // The three slots are independent: a wallet may have an
            // interest policy and no allocation policy, or vice versa, or
            // neither.
            // -----------------------------------------------------------------
            'interest_policy_version_id'   => $this->interest_policy_version_id !== null
                ? (int) $this->interest_policy_version_id
                : null,
            'allocation_policy_version_id' => $this->allocation_policy_version_id !== null
                ? (int) $this->allocation_policy_version_id
                : null,
            'kyc_policy_version_id'        => $this->kyc_policy_version_id !== null
                ? (int) $this->kyc_policy_version_id
                : null,

            // -----------------------------------------------------------------
            // Conditional relations (F-2B-LIST-1).
            //
            // Each key is present only when the relation was eager-loaded.
            // When absent, the key is OMITTED from the payload — see the
            // class docblock §"Conditional relations".
            //
            // The `ShareholderResource` class is imported from the
            // Shareholder domain. The `ObligationResource` and
            // `PaymentResource` classes live in this namespace and
            // therefore require no import.
            // -----------------------------------------------------------------
            'shareholder' => $this->whenLoaded(
                'shareholder',
                fn () => new ShareholderResource($this->shareholder),
            ),

            'obligations' => $this->whenLoaded(
                'obligations',
                fn () => ObligationResource::collection($this->obligations),
            ),

            'payments' => $this->whenLoaded(
                'payments',
                fn () => PaymentResource::collection($this->payments),
            ),

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // Each ISO field is followed by its `_display` sibling, as
            // above.
            // -----------------------------------------------------------------
            'created_at'         => $this->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($this->created_at),
            'updated_at'         => $this->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($this->updated_at),
        ];
    }
}