<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * StorePaymentRequest
 *
 * Validates input for recording a wallet payment.
 *
 * =============================================================================
 * What this request produces
 * =============================================================================
 * The validated payload drives PaymentService::record() (2B.17) via the
 * store() action of PaymentController (2B.28):
 *
 *     PaymentService::record(
 *         wallet:        <bound by route-model binding, tenant-scoped>,
 *         amount:        <from the validated payload>,
 *         receivedAt:    <from the validated payload, or null → now>,
 *         referenceType: <from the validated payload, or null>,
 *         referenceId:   <from the validated payload, or null>,
 *         description:   <from the validated payload, or null>,
 *         createdBy:     <from auth()->id()>,
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
 *     amount          — required. A POSITIVE decimal string with at most
 *                       four decimal places. The sign is NOT accepted: a
 *                       payment's direction is a property of the
 *                       operation (recording a receipt), not of the
 *                       value. The service (2B.17) passes the amount to
 *                       the ledger unchanged, producing a credit entry
 *                       under the member-position sign convention
 *                       (Architecture §4.2).
 *
 *                       Zero is rejected. A zero-amount payment has no
 *                       meaning, and the ledger-write primitive (2B.15)
 *                       refuses zero-value entries.
 *
 *     received_at     — optional ISO 8601 date-time. The business time of
 *                       the payment. When omitted, the service defaults
 *                       it to `now()`.
 *
 *     reference_type  — optional. Free text identifying the type of the
 *                       consuming-domain object the payment refers to
 *                       (e.g. `transfer`, `invoice`, `remittance`).
 *                       Opaque to this domain. Capped at 64 characters,
 *                       matching the `wallet_payments.reference_type`
 *                       column width.
 *
 *     reference_id    — optional. The identifier of that object. Opaque
 *                       to this domain. Capped at 64 characters, matching
 *                       the `wallet_payments.reference_id` column width.
 *
 *                       The reference pair is all-or-nothing: supplying
 *                       one without the other is a 422. See the section
 *                       "Cross-field checks" below.
 *
 *     description     — optional free text. Capped at 500 characters,
 *                       matching the `wallet_payments.description`
 *                       column width.
 *
 * Five fields, no more. The following are NOT accepted by this request:
 *
 *     wallet_id            — resolved from the route parameter.
 *
 *     currency             — inherited from the wallet by the service
 *                            (Q8). Accepting it would permit a caller to
 *                            forge a mismatched currency, which the
 *                            architecture forbids.
 *
 *     unapplied_amount     — a projection maintained by
 *                            AllocationService (2B.18). Never supplied
 *                            by a caller. It is initialized to equal
 *                            `amount` at creation (2B.17 §"record"
 *                            step 1).
 *
 *     created_by           — resolved from `auth()->id()` by the
 *                            controller. Accepting a different value
 *                            would defeat actor attribution.
 *
 * =============================================================================
 * Contrast with StoreObligationRequest (2B.22)
 * =============================================================================
 * This request differs from 2B.22 in three ways, each driven by a
 * substantive difference between the two models (2B.7 vs. 2B.6):
 *
 *   1. No `component` field. Payments are NOT component-tagged
 *      (Architecture §3.4, D109): the economic meaning is established by
 *      the ALLOCATIONS that later settle specific obligations. The ledger
 *      entry produced by a payment therefore carries a null `component`,
 *      which `WalletService::recordEntry()` (2B.15) enforces via
 *      `assertComponentConsistency()`.
 *
 *   2. No `due_at` field. Payments are not owed; they are received. The
 *      `wallet_payments` table has no `due_at` column (2B.51).
 *
 *   3. `received_at` replaces `occurred_at`. The business timestamp of a
 *      payment is when it was received, not when some fact about it
 *      "occurred". The model (2B.7) names the column accordingly.
 *
 * =============================================================================
 * Cross-field checks
 * =============================================================================
 * Two checks are performed in `withValidator()` after the base rules:
 *
 *   1. The amount must be non-zero at scale 4. The regex in `rules()`
 *      guarantees the format, but does not guarantee non-zero; the
 *      explicit bcmath check closes the gap. The service (2B.17)
 *      re-enforces the same rule as a defence.
 *
 *   2. The reference pair must be all-or-nothing. A caller supplying
 *      only `reference_type` or only `reference_id` receives a 422
 *      attached to the `reference_type` field. The service (2B.17)
 *      re-enforces the same rule as a defence.
 *
 * Both checks mirror the service's own rules verbatim, so a payload that
 * passes here will always pass there.
 *
 * =============================================================================
 * No overpayment detection (D110)
 * =============================================================================
 * A payment may exceed the total outstanding obligation amount on the
 * wallet. That fact is NOT detected by this request and is NOT an error.
 * D110 requires that the unapplied remainder be recorded explicitly — and
 * it is: as the payment's `unapplied_amount` column (2B.7, 2B.17 §"No
 * overpayment handling at record time"). A future allocation against the
 * payment reduces it; if the wallet has no obligations to allocate
 * against, the payment remains entirely unapplied indefinitely.
 *
 * A caller that wants to verify "does this payment settle every
 * outstanding obligation?" is a consumer concern; it is not part of
 * payment recording.
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
 * =============================================================================
 * No authorization here
 * =============================================================================
 * `authorize()` returns true. The route-level
 * `auth.permission:wallet.payment.record` middleware (Step 7 v1.1.0
 * §C.1, route #6) enforces the required permission before the request is
 * even instantiated. The controller may additionally invoke the
 * WalletPolicy (2B.37) for its own reasons. The FormRequest itself
 * performs no authorization.
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
 * strings.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.4    — Payment entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.4    — Overpayment and unapplied amounts
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.23     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs (wallet.payment.record)
 *   Step 7 v1.1.0 §C.1          — Route #6
 *                                 (POST /wallets/{wallet}/payments)
 *   §2.6 (conventions)          — FormRequest conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class StorePaymentRequest extends FormRequest
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.51 and matches the
     * service's own bound (2B.17 §MAX_DECIMAL_SCALE).
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * Maximum length of the `reference_type` and `reference_id` fields.
     *
     * Matches the `wallet_payments.reference_type` and
     * `wallet_payments.reference_id` column widths (2B.51).
     */
    private const MAX_REFERENCE_LENGTH = 64;

    /**
     * Maximum length of the `description` field.
     *
     * Matches the `wallet_payments.description` column width (2B.51).
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
     * Normalise string fields before validation.
     *
     * The following fields are trimmed of leading and trailing whitespace:
     *
     *     amount          — preserved as a decimal string after trimming.
     *     reference_type  — trimmed; an empty-after-trim value is
     *                       normalised to null.
     *     reference_id    — trimmed; an empty-after-trim value is
     *                       normalised to null.
     *     description     — trimmed; an empty-after-trim value is
     *                       normalised to null.
     *
     * Normalising empty strings to null has two consequences:
     *
     *   - The all-or-nothing reference-pair check treats "absent" and
     *     "explicitly empty" identically, so a caller sending
     *     `reference_type: ""` and no `reference_id` is not falsely
     *     rejected as providing half a pair.
     *
     *   - The service's `assertReferencePairIsConsistent()` (2B.17) has
     *     the same null-versus-empty behaviour, so a value that passes
     *     this request passes the service.
     *
     * Non-string input for any of these fields falls through to the
     * corresponding base rule, producing the appropriate 422.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (['amount', 'reference_type', 'reference_id', 'description'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $raw = $this->input($field);

            if (!is_string($raw)) {
                continue;
            }

            $trimmed = trim($raw);

            if ($trimmed === '' && $field !== 'amount') {
                $merged[$field] = null;
                continue;
            }

            $merged[$field] = $trimmed;
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
     * Five rules, one per accepted field. See the class docblock
     * §"Fields accepted by this request" for the exhaustive list of what
     * is and is not accepted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Amount — required, positive decimal, at most 4 decimals.
            //
            // The regex accepts an UNSIGNED decimal string. A sign is
            // deliberately not permitted: a payment's direction is a
            // property of the operation, not of the value (see the class
            // docblock §"Fields accepted by this request"). The non-zero
            // check is performed in `withValidator()` below.
            // -----------------------------------------------------------------
            'amount' => [
                'required',
                'string',
                'regex:/^\d+(\.\d{1,4})?$/',
            ],

            // -----------------------------------------------------------------
            // Business timestamp — optional ISO 8601 date-time.
            //
            // A payment received at a past time is a legitimate case (a
            // backfill of a historical receipt). The service does not
            // enforce a lower or upper bound; the timestamp is stored as
            // supplied.
            // -----------------------------------------------------------------
            'received_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            // -----------------------------------------------------------------
            // Reference pair — optional, all-or-nothing, opaque.
            //
            // Each half is independently capped at 64 characters. The
            // pairing rule is enforced in `withValidator()` below.
            // -----------------------------------------------------------------
            'reference_type' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_REFERENCE_LENGTH,
            ],

            'reference_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_REFERENCE_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Description — optional free text.
            // -----------------------------------------------------------------
            'description' => [
                'sometimes',
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
     * Adds two cross-field checks after the base rules:
     *
     *   1. `amount` must be non-zero at scale 4. The regex above
     *      guarantees the format but not non-zero.
     *
     *   2. `reference_type` and `reference_id` must be supplied together
     *      or omitted together.
     *
     * Both checks are guarded against running when the corresponding
     * format rule already failed, so that a malformed value does not
     * reach bcmath or produce duplicate error messages.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // -----------------------------------------------------------------
            // 1. Amount must be non-zero.
            // -----------------------------------------------------------------
            if (!$v->errors()->has('amount')) {
                $amount = $this->input('amount');

                if (is_string($amount) && bccomp($amount, '0', self::MAX_DECIMAL_SCALE) === 0) {
                    $v->errors()->add(
                        'amount',
                        __('wallet::validation.payment.amount.non_zero'),
                    );
                }
            }

            // -----------------------------------------------------------------
            // 2. Reference pair must be all-or-nothing.
            // -----------------------------------------------------------------
            if (
                !$v->errors()->has('reference_type')
                && !$v->errors()->has('reference_id')
            ) {
                $type = $this->input('reference_type');
                $id   = $this->input('reference_id');

                $hasType = is_string($type) && $type !== '';
                $hasId   = is_string($id) && $id !== '';

                if ($hasType !== $hasId) {
                    $v->errors()->add(
                        'reference_type',
                        __('wallet::validation.payment.reference.pair'),
                    );
                }
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
     * current locale. No English string is hard-coded.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => __('wallet::validation.payment.amount.required'),
            'amount.string'   => __('wallet::validation.payment.amount.string'),
            'amount.regex'    => __('wallet::validation.payment.amount.regex'),

            'received_at.date' => __('wallet::validation.payment.received_at.date'),

            'reference_type.string' => __('wallet::validation.payment.reference_type.string'),
            'reference_type.max'    => __('wallet::validation.payment.reference_type.max'),
            'reference_id.string'   => __('wallet::validation.payment.reference_id.string'),
            'reference_id.max'      => __('wallet::validation.payment.reference_id.max'),

            'description.string' => __('wallet::validation.payment.description.string'),
            'description.max'    => __('wallet::validation.payment.description.max'),
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
            'amount'         => __('wallet::attributes.payment.amount'),
            'received_at'    => __('wallet::attributes.payment.received_at'),
            'reference_type' => __('wallet::attributes.payment.reference_type'),
            'reference_id'   => __('wallet::attributes.payment.reference_id'),
            'description'    => __('wallet::attributes.payment.description'),
        ];
    }
}