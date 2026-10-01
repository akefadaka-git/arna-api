<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-L2
 * =============================================================================
 *
 * File:            mases_core/src/Domains/Loan/Http/Resources/LoanProductResource.php
 * Unit:            CR-L2 — Ethiopian calendar date rendering (Loan resources)
 * Delivery type:   Amendment — one import, one trait use, two
 *                  `_display` fields, docblock amendments.
 * Net lines:       +2 imports/use; +2 field lines; docblock revision.
 *
 * Before:
 *   Every date field the resource emitted was ISO 8601 only:
 *
 *     created_at, updated_at   (2 date fields)
 *
 *   A consumer that wanted the Amharic-first medium form had to slice
 *   the ISO string client-side, breaking the convention established by
 *   P3 · Unit 2 across the Shareholder resources, extended to
 *   WalletResource by CR-W1, and being extended to the six Loan
 *   resources by CR-L2.
 *
 * After:
 *   The resource mixes in RendersLocalizedDates (Reading 1:
 *   cross-domain import from the Shareholder domain, matching
 *   WalletResource's and the sibling Loan resources' precedent) and
 *   emits a `_display` sibling for each of its two date fields. The
 *   ISO fields are preserved byte-for-byte; the `_display` fields are
 *   additive.
 *
 *   Each `_display` field appears immediately after its ISO parent:
 *
 *     created_at_display, updated_at_display
 *
 *   Both ISO dates are non-null in practice (Laravel manages them on
 *   every save); the null-safe operator is applied uniformly for
 *   defensive consistency, matching the pattern used by every other
 *   resource in this CR.
 *
 * Public surface delta:
 *   · No new methods. The trait contributes one `protected localizedDate()`
 *     method, which is not part of the resource's external contract.
 *   · The serialised payload gains two keys. No key is removed,
 *     renamed, or re-valued. In particular, the bilingual name and
 *     description pairs, the structural envelope, and the
 *     `has_policy_version` computed boolean are unchanged.
 *
 * Dependency note:
 *   · The trait is imported from the Shareholder domain's Concerns
 *     namespace, matching the cross-domain import on LoanResource.php
 *     (CR-L2 file 1). Reading 1 was confirmed: the trait stays where
 *     it is for CR-L2.
 *
 *   · The trait and LocalizationService::formatEthiopianDateMedium()
 *     are both deployed (Track A closed).
 *
 *   · No new class, no config change, no route change. No Composer
 *     refresh is required for this file alone.
 *
 * =============================================================================
 */

/*
 * =============================================================================
 * MASES Phase 2C — Loan Product Resource
 * =============================================================================
 *
 * File:      mases_core/src/Domains/Loan/Http/Resources/LoanProductResource.php
 * Version:   1.1.0
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;

/**
 * LoanProductResource
 *
 * The HTTP representation of a `LoanProduct` catalogue entry (A.17).
 * Serializes a product's identity, its bilingual names and descriptions,
 * its structural envelope (currency, bounds, default frequency), its
 * lifecycle flags, and its policy reference.
 *
 * =============================================================================
 * Shape
 * =============================================================================
 * The resource emits five groups of fields:
 *
 *   1. IDENTITY — the product's primary key, its tenant scope, and its
 *      product code.
 *
 *   2. NAMES AND DESCRIPTIONS — the bilingual pairs (`name_am`/`name_en`,
 *      `description_am`/`description_en`) plus a locale-resolved
 *      `display_name` computed by the model's `displayName()` helper
 *      (A.17).
 *
 *   3. STRUCTURAL ENVELOPE — the product's currency, its principal
 *      bounds (`min_principal`, `max_principal`), its term bounds
 *      (`min_term_months`, `max_term_months`), and its default
 *      repayment frequency.
 *
 *   4. POLICY REFERENCE AND FLAGS — the versioned policy version id
 *      (`product_rules_policy_version_id`), a computed
 *      `has_policy_version` boolean, and the `is_active` flag.
 *
 *   5. TIMESTAMPS — `created_at` and `updated_at`, each ISO 8601 with
 *      a localized `_display` sibling.
 *
 * The resource exposes no `whenLoaded` relations. A product's
 * relations (`tenant`, `policyVersion`, `loans`) are not part of its
 * canonical catalogue representation. A caller that needs the policy
 * payload resolves it through the Core VersionedPolicyRegistry on their
 * own read path; a caller that needs the tenant entity resolves it
 * through its own read path.
 *
 * =============================================================================
 * Bilingual emission — both languages always present
 * =============================================================================
 * The resource emits BOTH `name_am` and `name_en` (and both description
 * variants) on every product, regardless of the request's active locale.
 *
 * The Amharic-first convention (handoff §3) is a DISPLAY convention,
 * resolved at the presentation layer via `display_name`. It is not a
 * serialization convention: a resource that omitted the non-dominant
 * language would prevent an API consumer from switching locales on the
 * client side. Emitting both languages gives a caller the full
 * information; the caller selects which one to display.
 *
 * The `display_name` field applies the resolution rule:
 *
 *   - `am` locale → `name_am` first; fallback to `name_en`; last resort
 *     to `code`.
 *   - every other locale → `name_en` first; fallback to `name_am`; last
 *     resort to `code`.
 *
 * The rule is implemented on the model (A.17 §"`displayName()`"); this
 * resource delegates to it. `display_name` is therefore never empty, not
 * even when both language columns are null (the code is the last
 * resort).
 *
 * =============================================================================
 * Monetary values are decimal strings
 * =============================================================================
 * The two monetary/bound fields (`min_principal`, `max_principal`) are
 * emitted as the strings the `decimal:4` casts produce (A.17's `$casts`
 * array). No arithmetic is performed inside this resource.
 *
 * The term bounds (`min_term_months`, `max_term_months`) are integers and
 * emit as JSON integers.
 *
 * The resource NEVER casts a monetary value to float. The platform-wide
 * no-floats discipline (handoff §3) applies to the resource layer as
 * strictly as it applies to the service layer.
 *
 * =============================================================================
 * `has_policy_version` — a computed boolean
 * =============================================================================
 * The resource emits `has_policy_version`, a boolean derived from the
 * model's `hasPolicyVersion()` helper (A.17). The helper answers whether
 * `product_rules_policy_version_id` is non-null.
 *
 * The raw `product_rules_policy_version_id` is ALSO emitted, so a caller
 * that needs the id reads it directly and a caller that only needs the
 * boolean reads the flag. The two fields are redundant by design: the
 * boolean is the expressive form for a form-validation path ("disable
 * the enable-product button until a policy is attached"), while the raw
 * id is the addressing form for a follow-up API call.
 *
 * =============================================================================
 * Dates — ISO 8601 and localized display siblings  (CR-L2)
 * =============================================================================
 * Two date fields are emitted. Each is accompanied by a `_display`
 * sibling produced by LocalizationService::formatEthiopianDateMedium()
 * via the RendersLocalizedDates trait (imported cross-domain from the
 * Shareholder domain, matching LoanResource.php's precedent):
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
 * The two timestamps are non-null in practice — Laravel manages them on
 * every save — but the null-safe operator is applied uniformly for
 * defensive consistency, matching the pattern used throughout CR-L2.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does NOT trigger lazy-loads. The resource accesses only the
 *     product's own columns and its `displayName()` helper, both of
 *     which are resolved without additional I/O.
 *
 *   - It does NOT cast a monetary value to float. See §"Monetary values
 *     are decimal strings".
 *
 *   - It does NOT emit the policy payload. A caller that needs the
 *     payload resolves the version through the Core
 *     VersionedPolicyRegistry on their own read path. Denormalizing the
 *     payload into every product response would bloat the payload and
 *     would duplicate the registry's content across every read.
 *
 *   - It does NOT emit the product's `loans` relation. A caller that
 *     needs the loan count composes a separate aggregate query; the
 *     product's own representation does not carry it.
 *
 *   - It does NOT emit the `tenant` entity. The `tenant_id` column is
 *     emitted directly.
 *
 *   - It does NOT perform authorization. The controller gates the
 *     endpoint through `Gate::authorize(...)`; the resource is a pure
 *     serializer.
 *
 *   - It does NOT emit an `is_active` label (e.g. "Active" /
 *     "Inactive"). Display strings belong to the resource layer's
 *     presentation logic; this resource emits the raw boolean, and a
 *     client-facing layer that needs a label resolves it through the
 *     localization system's `__()` mechanism.
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
 *             LoanResource.php precedent from CR-L2 file 1) and mixed
 *             into the class.
 *
 *           · Two `_display` sibling fields are added — one for
 *             `created_at`, one for `updated_at`. Each `_display` field
 *             appears immediately after its ISO sibling.
 *
 *           · The ISO fields are preserved byte-for-byte. Every existing
 *             key retains its name, its type, and its value; the
 *             `_display` fields are additive.
 *
 *           · No structural change. No new dependency beyond the trait.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130 (product
 *                                                  catalogue), D132
 *                                                  (term structure),
 *                                                  B6 (hybrid storage)
 *   Phase 2C Clarification Response, 2026-09-12  — Extra 1 (tenant
 *                                                  scoping),
 *                                                  Extra 2 (single
 *                                                  policy type)
 *   Phase 2C A.8                                 — `loan_products`
 *                                                  migration (the
 *                                                  bilingual columns and
 *                                                  the UNIQUE
 *                                                  (tenant_id, code)
 *                                                  constraint)
 *   Phase 2C A.17                                — `LoanProduct` (the
 *                                                  model serialized by
 *                                                  this resource)
 *   Phase 2C A.56                                — `LoanProductRepository`
 *                                                  (the read path that
 *                                                  feeds this resource)
 *   Phase 2C A.69                                — `LoanResource` (the
 *                                                  sibling that embeds
 *                                                  this resource via
 *                                                  `whenLoaded`)
 *   Phase 2C A.78                                — `LoanProductController`
 *                                                  (the primary HTTP
 *                                                  consumer)
 *   Phase 2A 2A.23                               — `ShareClassResource`
 *                                                  (the shape precedent
 *                                                  for a bilingual
 *                                                  catalogue resource)
 *   Phase 2B 2B.23                               — `WalletResource` (the
 *                                                  sibling resource)
 *   P3 · Unit 2                                  — Ethiopian calendar
 *                                                  date rendering (the
 *                                                  convention this
 *                                                  amendment extends)
 *   CR-W1                                        — the cross-domain trait
 *                                                  import precedent
 *   CR-L2                                        — This amendment
 *   Step 5 (manifest) A.70                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Resources
 * @version 1.1.0
 */
final class LoanProductResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the automatic `data` wrapping.
     *
     * See the sibling note on `LoanResource` (A.69) §"Deployment Notes"
     * (item 2) for the rationale. The controller's response envelope is
     * the single wrapping layer.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the product into its array representation.
     *
     * See the class docblock for the field layout and the
     * no-lazy-loads / no-floats / bilingual-always /
     * ISO-plus-`_display` contract.
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
            'id'        => (int) $this->id,
            'tenant_id' => (int) $this->tenant_id,
            'code'      => (string) $this->code,

            // -----------------------------------------------------------------
            // Bilingual names.
            //
            // Both languages are always emitted, regardless of the active
            // locale. `display_name` applies the Amharic-first resolution
            // rule at the model level.
            // -----------------------------------------------------------------
            'name_am'      => $this->name_am,
            'name_en'      => $this->name_en,
            'display_name' => $this->displayName(),

            // -----------------------------------------------------------------
            // Bilingual descriptions.
            // -----------------------------------------------------------------
            'description_am' => $this->description_am,
            'description_en' => $this->description_en,

            // -----------------------------------------------------------------
            // Free-text purpose.
            // -----------------------------------------------------------------
            'purpose' => $this->purpose,

            // -----------------------------------------------------------------
            // Structural envelope.
            //
            // The two monetary bounds are decimal strings; the two term
            // bounds are integers.
            // -----------------------------------------------------------------
            'currency'        => $this->currency,
            'min_principal'   => (string) $this->min_principal,
            'max_principal'   => (string) $this->max_principal,
            'min_term_months' => (int) $this->min_term_months,
            'max_term_months' => (int) $this->max_term_months,

            // -----------------------------------------------------------------
            // Repayment frequency.
            //
            // The frozen D132 freeze declares monthly as the only
            // supported frequency; the column carries the value forward
            // so that a future CR that introduces additional frequencies
            // does not have to alter the schema.
            // -----------------------------------------------------------------
            'default_installment_frequency' => (string) $this->default_installment_frequency,

            // -----------------------------------------------------------------
            // Policy reference and lifecycle flags.
            // -----------------------------------------------------------------
            'product_rules_policy_version_id' => $this->product_rules_policy_version_id,
            'has_policy_version'              => $this->hasPolicyVersion(),
            'is_active'                       => (bool) $this->is_active,

            // -----------------------------------------------------------------
            // Timestamps — ISO + localized display, paired.  (CR-L2)
            // -----------------------------------------------------------------
            'created_at'         => $this->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($this->created_at),
            'updated_at'         => $this->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($this->updated_at),
        ];
    }
}