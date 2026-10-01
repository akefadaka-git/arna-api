<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * OpenWalletRequest
 *
 * Validates input for opening a wallet.
 *
 * =============================================================================
 * What this request produces
 * =============================================================================
 * The validated payload drives WalletService::open() (2B.15) via the
 * store() action of WalletController (2B.26):
 *
 *     WalletService::open(
 *         tenantId:     <from mases_tenant_id()>,
 *         shareholder:  <resolved from shareholder_id>,
 *         currency:     <from the validated payload>,
 *     )
 *
 * The controller resolves the shareholder through the tenant-scoped
 * ShareholderRepository (2A.11) BEFORE invoking the service. The service
 * then re-verifies the shareholder's tenant_id as a defence-in-depth
 * check (2B.15 §"assertShareholderBelongsToTenant"). See the section
 * "Tenant scoping" below for the full sequence.
 *
 * =============================================================================
 * Fields accepted by this request
 * =============================================================================
 *     shareholder_id   — required, integer, must reference an existing
 *                        shareholder. Resolved by the controller through
 *                        the tenant-scoped repository before the service
 *                        runs. See "Tenant scoping".
 *
 *     currency         — required, three ASCII letters (ISO 4217 shape).
 *                        Uppercased by prepareForValidation(). No allow-
 *                        list; the architecture deliberately omits one
 *                        (2B.15 §"Currency handling").
 *
 * Two fields, no more. The wallet's other attributes are set by the
 * service:
 *
 *     tenant_id        — server-resolved from `mases_tenant_id()`
 *     current_balance  — set to `0.0000` (no monetary operation on open)
 *     is_active        — set to `true`
 *     opened_at        — set to `now()`
 *     closed_at        — null
 *     *_policy_version_id  — null; policy attachment is deferred (A7, A8,
 *                            D110; see 2B.5 §"Policy references")
 *
 * The three policy-version fields are NOT accepted by this request.
 * Attaching a policy to a wallet is a separate, deferred operation; a
 * future Change Request will add a dedicated request. Allowing them here
 * would invite the caller to set a policy reference that no 2B code path
 * evaluates.
 *
 * =============================================================================
 * Tenant scoping (Step 7 v1.1.0 §C.6)
 * =============================================================================
 * The `shareholder_id` rule uses `exists:shareholders,id` — a SHAPE check
 * that the id references some shareholder row anywhere in the database.
 * It does NOT enforce the tenant boundary.
 *
 * The tenant boundary is enforced by the controller, which resolves the
 * shareholder through `ShareholderRepository::findForTenant($tenantId,
 * $shareholderId)` (2A.11). A shareholder belonging to a different tenant
 * yields `null`, and the controller short-circuits with the same 404 a
 * non-existent id would produce.
 *
 * This split is deliberate and matches the pattern frozen by Phase 2A
 * (StoreCertificateRequest, 2A.20):
 *
 *   - The FormRequest rejects clearly malformed input with a 422.
 *   - The controller rejects cross-tenant references with a 404.
 *
 * The alternative — a single `exists` rule that is simultaneously
 * tenant-scoped — is not expressible via Laravel's rule DSL without a
 * closure, and a closure-based rule would couple the request to the
 * repository layer and duplicate the tenant-resolution concern that the
 * controller already owns.
 *
 * The service (2B.15) performs a third, defensive check: it verifies
 * `$shareholder->tenant_id === $tenantId` before persisting, on the
 * assumption that the caller passed a hydrated model. This is belt-and-
 * braces; the controller is the primary enforcement point.
 *
 * =============================================================================
 * Currency format vs. minor-unit precision (Q2, Q8)
 * =============================================================================
 * This request validates the currency's FORMAT only: three ASCII letters,
 * matching the ISO 4217 shape. It does NOT validate that the code
 * corresponds to a known currency, and it does NOT validate per-currency
 * minor-unit precision.
 *
 * Rationale (2B.15 §"Currency handling"):
 *
 *   - No currency allow-list. The platform's operating currency is a
 *     business-policy concern; the architecture deliberately omits a
 *     config-level allow-list.
 *
 *   - Minor-unit precision enforcement is deferred. The full rule
 *     ("ETB has 2 minor units, BHD has 3, ...") requires a shared
 *     currency-precision capability that Phase 2B does not introduce.
 *     A future CR adds it. Until then, format is the only check.
 *
 * The service re-validates the format defensively so that a request
 * bypassing this FormRequest (tests, artisan scripts) cannot persist a
 * malformed currency code.
 *
 * =============================================================================
 * No authorization here
 * =============================================================================
 * `authorize()` returns true. The route-level `auth.permission:wallet.open`
 * middleware (Step 7 v1.1.0 §C.1, route #2) enforces the required
 * permission before the request is even instantiated, and the controller
 * may additionally invoke the WalletPolicy (2B.37) for its own reasons.
 * The FormRequest itself performs no authorization. This matches the
 * convention established by Phase 2A's FormRequests.
 *
 * =============================================================================
 * Amharic-first error messages
 * =============================================================================
 * No hard-coded English strings. Every message and attribute name is a
 * translation key resolved through Laravel's translator in the current
 * locale, using the `wallet::validation.*` and `wallet::attributes.*`
 * namespaces.
 *
 * As of Phase 2B, the `wallet` translation files are NOT yet authored;
 * keys resolve to their own string until a future CR (analogous to
 * CR-009 for the Shareholder domain) supplies the Amharic and English
 * strings. The request is written to the correct convention regardless —
 * adding the translation files later requires no change to this file.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §4.4    — Precision and currency (Q2, Q8)
 *   Architecture v1.2.0 §18     — Explicitly deferred items
 *   Architecture v1.2.0 §19     — Q4, Q8 resolutions
 *   Step 5 (manifest) 2B.20     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs
 *   Step 7 v1.1.0 §C.1          — Route #2 (POST /wallets)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.6 (conventions)          — FormRequest conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class OpenWalletRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Always true — see the class docblock §"No authorization here".
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    // =========================================================================
    // Preparation
    // =========================================================================

    /**
     * Normalise the currency code before validation.
     *
     * The `currency` field is uppercased and trimmed of leading and
     * trailing whitespace. This lets a caller send `etb`, ` ETB`, or
     * `Etb` and have all three resolve to the canonical `ETB`.
     *
     * The `shareholder_id` field is left untouched: it is an integer, and
     * any non-numeric input is rejected by the `integer` rule with the
     * standard 422.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('currency')) {
            return;
        }

        $raw = $this->input('currency');

        if (!is_string($raw)) {
            // Non-string input falls through to the `string` rule, which
            // produces the appropriate 422. The request does not attempt
            // to coerce a non-string into a string.
            return;
        }

        $this->merge([
            'currency' => strtoupper(trim($raw)),
        ]);
    }

    // =========================================================================
    // Rules
    // =========================================================================

    /**
     * Get the validation rules that apply to the request.
     *
     * Two rules, one per accepted field. See the class docblock
     * §"Fields accepted by this request" for the exhaustive list of what
     * is and is not accepted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Shareholder reference — shape-only.
            //
            // `exists:shareholders,id` checks that the id references SOME
            // shareholder row anywhere in the database. The tenant boundary
            // is enforced by the controller's tenant-scoped resolution
            // before the service is invoked. See the class docblock
            // §"Tenant scoping".
            // -----------------------------------------------------------------
            'shareholder_id' => [
                'required',
                'integer',
                'min:1',
                'exists:shareholders,id',
            ],

            // -----------------------------------------------------------------
            // Currency — ISO 4217 shape only.
            //
            // The regex `/^[A-Za-z]{3}$/` enforces exactly three ASCII
            // letters. `prepareForValidation()` uppercases the value, so
            // in practice only `[A-Z]{3}` reaches this rule; the `[A-Za-z]`
            // character class is retained for defensive explicitness.
            //
            // No allow-list, no minor-unit precision check. See the class
            // docblock §"Currency format vs. minor-unit precision".
            // -----------------------------------------------------------------
            'currency' => [
                'required',
                'string',
                'regex:/^[A-Za-z]{3}$/',
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Currently no cross-field checks are needed — the two fields are
     * independent. The method is retained so that a future CR (e.g. an
     * optional `representative_user_id` for a legal-entity-shareholder
     * wallet, or a policy-attachment field once that operation is added)
     * has an obvious extension point.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        // No additional checks in Phase 2B.
    }

    // =========================================================================
    // Messages and attributes
    // =========================================================================

    /**
     * Get the custom validation messages.
     *
     * Every message is a translation key resolved via `__()` in the
     * current locale. No English string is hard-coded. See the class
     * docblock §"Amharic-first error messages".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shareholder_id.required' => __('wallet::validation.open.shareholder_id.required'),
            'shareholder_id.integer'  => __('wallet::validation.open.shareholder_id.integer'),
            'shareholder_id.min'      => __('wallet::validation.open.shareholder_id.min'),
            'shareholder_id.exists'   => __('wallet::validation.open.shareholder_id.exists'),

            'currency.required' => __('wallet::validation.open.currency.required'),
            'currency.string'   => __('wallet::validation.open.currency.string'),
            'currency.regex'    => __('wallet::validation.open.currency.regex'),
        ];
    }

    /**
     * Get human-readable field names for error messages.
     *
     * Every attribute name is a translation key resolved via `__()` in
     * the current locale.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'shareholder_id' => __('wallet::attributes.open.shareholder_id'),
            'currency'       => __('wallet::attributes.open.currency'),
        ];
    }
}