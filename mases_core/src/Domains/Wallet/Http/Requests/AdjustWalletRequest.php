<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * AdjustWalletRequest
 *
 * Validates input for an operator-initiated wallet balance adjustment.
 *
 * =============================================================================
 * What this request produces
 * =============================================================================
 * The validated payload drives WalletService::adjust() (2B.15) via the
 * adjust() action of WalletController (2B.26):
 *
 *     WalletService::adjust(
 *         wallet:       <bound by route-model binding, tenant-scoped>,
 *         signedAmount: <from the validated payload>,
 *         createdBy:    <from auth()->id()>,
 *         description:  <from the validated payload>,
 *     )
 *
 * The wallet is resolved by the controller through
 * `WalletRepository::findForTenant()` (2B.11) BEFORE this request's
 * validated payload reaches the service. See the class docblock
 * §"Tenant scoping" below.
 *
 * =============================================================================
 * Fields accepted by this request
 * =============================================================================
 *     signed_amount   — required. A SIGNED decimal string with at most
 *                       four decimal places. The sign is significant:
 *
 *                           "+500.0000"  → credit the member
 *                           "-500.0000"  → debit the member
 *
 *                       Per Architecture §4.2, the sign carries the
 *                       direction of the movement; there is no separate
 *                       direction field. A value with no explicit sign
 *                       ("500.0000") is interpreted as positive.
 *
 *                       Zero ("0", "0.0000", "+0.0000", "-0.0000") is
 *                       rejected: the ledger-write primitive (2B.15
 *                       §"assertSignedQuantityIsWellFormed") refuses
 *                       zero-value entries, and a zero adjustment has no
 *                       meaning.
 *
 *     description     — optional. Free text; capped at 500 characters,
 *                       matching the `wallet_entries.description` column
 *                       width (2B.54).
 *
 * Two fields, no more. The following are NOT accepted by this request:
 *
 *     wallet_id       — resolved from the route parameter, not the body.
 *
 *     created_by      — resolved from `auth()->id()` by the controller,
 *                       never from caller input. An adjustment's actor is
 *                       the authenticated user; accepting a different
 *                       value from the payload would be an
 *                       authorization-defeating pattern.
 *
 *     reference_type  — hardcoded to 'Wallet' by the service (2B.15
 *     reference_id      §"adjust"). An adjustment's source object is the
 *                       wallet itself; no other reference is meaningful.
 *                       Accepting these fields would allow a caller to
 *                       forge a reference the ledger's §5.3 mapping does
 *                       not permit.
 *
 *     entry_type      — hardcoded to `WalletEntryType::Adjustment` by
 *                       the service. A caller cannot choose an entry
 *                       type.
 *
 *     occurred_at     — hardcoded to `now()` by the service. An
 *                       adjustment is a correction issued at the moment
 *                       of the request; a retrospective adjustment is
 *                       not part of the 2B scope.
 *
 * =============================================================================
 * Why this request exists at all (privileged operation, Q3)
 * =============================================================================
 * `wallet.adjust` is the most privileged wallet permission in the frozen
 * RBAC matrix (Step 7 v1.1.0 §A.2.1): held only by `super_admin`. It is
 * the sole mechanism in Phase 2B by which an operator may change a
 * wallet's monetary balance outside the obligation/payment lifecycle. The
 * dedicated FormRequest is part of the discipline:
 *
 *   - The route-level `auth.permission:wallet.adjust` middleware (Step 7
 *     v1.1.0 §C.1, route #4) gates the operation.
 *   - This request constrains the payload to the two fields the operation
 *     accepts.
 *   - The service (2B.15) enforces the signed-quantity format, the
 *     non-zero rule, and the wallet-lock discipline.
 *
 * All three layers are needed. A request that accepted additional fields
 * would widen the adjust operation's surface without widening its
 * authorization.
 *
 * =============================================================================
 * Tenant scoping (Step 7 v1.1.0 §C.6)
 * =============================================================================
 * The target wallet is bound by the route (`{wallet}`) and resolved by
 * the controller through `WalletRepository::findForTenant($tenantId,
 * $walletId)` (2B.11). A cross-tenant wallet id yields `null`, and the
 * controller short-circuits with the same 404 a non-existent id would
 * produce.
 *
 * This request performs no tenant resolution of its own. It validates
 * only the shape of the payload; the wallet's scope was established
 * before the request's rules were evaluated.
 *
 * The request deliberately does NOT accept a `wallet_id` field. A body
 * that supplies one is ignored — the target wallet is the route-bound
 * model, never a caller-supplied identifier.
 *
 * =============================================================================
 * No authorization here
 * =============================================================================
 * `authorize()` returns true. The route-level
 * `auth.permission:wallet.adjust` middleware enforces the required
 * permission before the request is even instantiated. The controller may
 * additionally invoke the WalletPolicy (2B.37) for its own reasons. The
 * FormRequest itself performs no authorization. This matches the
 * convention established by Phase 2A's FormRequests and by 2B.20.
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
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §19     — Q3 resolution (adjustment privileged)
 *   Step 5 (manifest) 2B.21     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs (wallet.adjust)
 *   Step 7 v1.1.0 §A.2.1        — Platform_admin excluded from wallet.adjust
 *   Step 7 v1.1.0 §C.1          — Route #4 (POST /wallets/{wallet}/adjust)
 *   §2.6 (conventions)          — FormRequest conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class AdjustWalletRequest extends FormRequest
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.54 and matches
     * the service's own bound (2B.15 §MAX_DECIMAL_SCALE).
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * Maximum length of the `description` field.
     *
     * Matches the `wallet_entries.description` column width (2B.54). The
     * same bound applies to `WalletService::adjust()` via the shared
     * column contract.
     */
    private const MAX_DESCRIPTION_LENGTH = 500;

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
     * Normalise the signed amount before validation.
     *
     * The `signed_amount` field is trimmed of leading and trailing
     * whitespace. The sign, if present, is preserved; digits and decimal
     * point are preserved; no reformatting is performed. Any coercion of
     * the string (e.g. removing a redundant leading `+`) is deliberately
     * avoided — the string is passed to the service, which parses it
     * with bcmath at scale 4.
     *
     * Non-string input falls through to the `string` rule, which produces
     * the appropriate 422. The request does not attempt to coerce a
     * non-string into a string.
     *
     * The `description` field is trimmed of leading and trailing
     * whitespace. An empty-after-trim description is left as an empty
     * string; the `nullable` rule does not treat it as null, but the
     * service accepts an empty string harmlessly (it is stored as-is and
     * rendered as empty in the statement projection).
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('signed_amount')) {
            $raw = $this->input('signed_amount');

            if (is_string($raw)) {
                $merged['signed_amount'] = trim($raw);
            }
        }

        if ($this->has('description')) {
            $raw = $this->input('description');

            if (is_string($raw)) {
                $merged['description'] = trim($raw);
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
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
            // Signed amount — required, signed decimal, at most 4 decimals.
            //
            // The regex accepts an optional leading sign, then digits,
            // then an optional fractional part of at most four digits. It
            // mirrors the service's own regex (2B.15
            // §"assertSignedQuantityIsWellFormed") exactly, so a value
            // that passes here will always pass there. The non-zero check
            // is performed in `withValidator()` below, using bcmath, to
            // avoid depending on regex-only zero detection.
            // -----------------------------------------------------------------
            'signed_amount' => [
                'required',
                'string',
                'regex:/^[+-]?\d+(\.\d{1,4})?$/',
            ],

            // -----------------------------------------------------------------
            // Description — optional free text.
            //
            // Capped at 500 characters, matching the
            // `wallet_entries.description` column width (2B.54). The
            // `sometimes` rule is not needed: `nullable` covers both the
            // "field absent" and "field null" cases.
            // -----------------------------------------------------------------
            'description' => [
                'nullable',
                'string',
                'max:' . self::MAX_DESCRIPTION_LENGTH,
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Adds one cross-rule check: the signed amount must be non-zero. The
     * check is performed here (rather than as a custom rule) so that the
     * regex above remains the single source of truth for the value's
     * format, and the non-zero check is expressed explicitly and legibly.
     *
     * The comparison uses bcmath at scale 4, mirroring the service's own
     * zero check (2B.15 §"assertSignedQuantityIsWellFormed"). A value of
     * `"0"`, `"0.0000"`, `"+0.0000"`, or `"-0.0000"` fails. A value of
     * `"0.0001"` passes — it is non-zero at scale 4.
     *
     * If the format rule has already failed, the amount field will not
     * match the regex and may be a non-numeric string; the guard below
     * checks that the field passes the format rule before invoking
     * bcmath, so that a malformed value does not raise a bcmath
     * `ValueError`.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('signed_amount')) {
                return;
            }

            $amount = $this->input('signed_amount');

            if (!is_string($amount)) {
                return;
            }

            if (bccomp($amount, '0', self::MAX_DECIMAL_SCALE) === 0) {
                $v->errors()->add(
                    'signed_amount',
                    __('wallet::validation.adjust.signed_amount.non_zero'),
                );
            }
        });
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
            'signed_amount.required' => __('wallet::validation.adjust.signed_amount.required'),
            'signed_amount.string'   => __('wallet::validation.adjust.signed_amount.string'),
            'signed_amount.regex'    => __('wallet::validation.adjust.signed_amount.regex'),

            'description.string' => __('wallet::validation.adjust.description.string'),
            'description.max'    => __('wallet::validation.adjust.description.max'),
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
            'signed_amount' => __('wallet::attributes.adjust.signed_amount'),
            'description'   => __('wallet::attributes.adjust.description'),
        ];
    }
}