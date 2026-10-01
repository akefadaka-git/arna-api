<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;

/**
 * StoreObligationRequest
 *
 * Validates input for recording a wallet obligation.
 *
 * =============================================================================
 * What this request produces
 * =============================================================================
 * The validated payload drives ObligationService::record() (2B.16) via the
 * store() action of ObligationController (2B.27):
 *
 *     ObligationService::record(
 *         wallet:        <bound by route-model binding, tenant-scoped>,
 *         component:     <from the validated payload>,
 *         amount:        <from the validated payload>,
 *         occurredAt:    <from the validated payload, or null → now>,
 *         dueAt:         <from the validated payload, or null>,
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
 *     component       — required. One of the six ObligationComponent cases
 *                       (2B.3): principal, interest, fee, penalty, tax,
 *                       other.
 *
 *     amount          — required. A POSITIVE decimal string with at most
 *                       four decimal places. The sign is NOT accepted: an
 *                       obligation's direction is a property of the
 *                       operation (recording a debt), not of the value.
 *                       The service (2B.16) applies the negative sign at
 *                       the ledger-write boundary.
 *
 *                       Zero is rejected. A zero-amount obligation has no
 *                       meaning, and the ledger-write primitive (2B.15)
 *                       refuses zero-value entries.
 *
 *     occurred_at     — optional ISO 8601 date-time. The business time of
 *                       the obligation. When omitted, the service
 *                       defaults it to `now()`.
 *
 *     due_at          — optional ISO 8601 date-time. The obligation's
 *                       maturity, if any. When omitted, the obligation is
 *                       open-ended.
 *
 *                       The service does NOT enforce `due_at > occurred_at`.
 *                       A retrospective obligation with a past due date is
 *                       a legitimate case; see 2B.16 §"What this service
 *                       deliberately does NOT do".
 *
 *     reference_type  — optional. Free text identifying the type of the
 *                       consuming-domain object the obligation refers to
 *                       (e.g. `loan`, `subscription`). Opaque to this
 *                       domain. Capped at 64 characters, matching the
 *                       `wallet_obligations.reference_type` column width.
 *
 *     reference_id    — optional. The identifier of that object. Opaque
 *                       to this domain. Capped at 64 characters, matching
 *                       the `wallet_obligations.reference_id` column
 *                       width.
 *
 *                       The reference pair is all-or-nothing: supplying
 *                       one without the other is a 422. See the section
 *                       "Cross-field checks" below.
 *
 *     description     — optional free text. Capped at 500 characters,
 *                       matching the `wallet_obligations.description`
 *                       column width.
 *
 * Seven fields, no more. The following are NOT accepted by this request:
 *
 *     wallet_id            — resolved from the route parameter.
 *
 *     currency             — inherited from the wallet by the service
 *                            (Q8). Accepting it would permit a caller to
 *                            forge a mismatched currency, which the
 *                            architecture forbids.
 *
 *     original_amount      — this request's `amount` field drives it.
 *                            The service sets both `original_amount` and
 *                            `outstanding_amount` to the same value at
 *                            creation (2B.16 §"record" step 1).
 *
 *     outstanding_amount   — a projection maintained by
 *                            AllocationService (2B.18). Never supplied
 *                            by a caller.
 *
 *     created_by           — resolved from `auth()->id()` by the
 *                            controller. Accepting a different value
 *                            would defeat actor attribution.
 *
 * =============================================================================
 * Cross-field checks
 * =============================================================================
 * Two checks are performed in `withValidator()` after the base rules:
 *
 *   1. The amount must be non-zero at scale 4. The regex in `rules()`
 *      guarantees the format, but does not guarantee non-zero; the
 *      explicit bcmath check closes the gap. The service (2B.16)
 *      re-enforces the same rule as a defence.
 *
 *   2. The reference pair must be all-or-nothing. A caller supplying
 *      only `reference_type` or only `reference_id` receives a 422
 *      attached to the `reference_type` field. The service (2B.16)
 *      re-enforces the same rule as a defence.
 *
 * Both checks mirror the service's own rules verbatim, so a payload that
 * passes here will always pass there. The duplication is deliberate: the
 * request produces the friendly 422; the service guards against callers
 * that bypass the HTTP layer.
 *
 * =============================================================================
 * Component validation (Rule::enum)
 * =============================================================================
 * The `component` field is validated with `Rule::enum(ObligationComponent::class)`,
 * Laravel's first-class enum rule. It accepts the case NAME, the case
 * VALUE, or the case instance, and normalises internally. A value outside
 * the closed six-case vocabulary produces a 422.
 *
 * This is the single source of truth for the component vocabulary: the
 * enum (2B.3) declares the cases; the rule rejects everything else. No
 * `in:` rule with a duplicated list is used — that would create a second
 * source that could drift.
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
 * `auth.permission:wallet.obligation.create` middleware (Step 7 v1.1.0
 * §C.1, route #5) enforces the required permission before the request is
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
 * strings. The request is written to the correct convention regardless —
 * adding the translation files later requires no change to this file.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D109                         — Obligation types
 *   Architecture v1.2.0 §3.3    — Obligation entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.22     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs (wallet.obligation.create)
 *   Step 7 v1.1.0 §C.1          — Route #5
 *                                 (POST /wallets/{wallet}/obligations)
 *   §2.6 (conventions)          — FormRequest conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class StoreObligationRequest extends FormRequest
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.50 and matches the
     * service's own bound (2B.16 §MAX_DECIMAL_SCALE).
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * Maximum length of the `reference_type` and `reference_id` fields.
     *
     * Matches the `wallet_obligations.reference_type` and
     * `wallet_obligations.reference_id` column widths (2B.50).
     */
    private const MAX_REFERENCE_LENGTH = 64;

    /**
     * Maximum length of the `description` field.
     *
     * Matches the `wallet_obligations.description` column width (2B.50).
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
     *   - The service's `assertReferencePairIsConsistent()` (2B.16) has
     *     the same null-versus-empty behaviour, so a value that passes
     *     this request passes the service.
     *
     * Non-string input for any of these fields falls through to the
     * corresponding base rule (e.g. `string`), producing the appropriate
     * 422. The request does not attempt to coerce non-string input.
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

            // For the reference pair and description, an empty-after-trim
            // string is normalised to null. For `amount`, an empty string
            // is left as an empty string so that the `required` rule
            // catches it clearly.
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
     * Seven rules, one per accepted field. See the class docblock
     * §"Fields accepted by this request" for the exhaustive list of what
     * is and is not accepted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Component — required. Closed enum vocabulary.
            //
            // `Rule::enum(ObligationComponent::class)` accepts the case
            // NAME, the case VALUE, or the case instance. A value outside
            // the closed six-case vocabulary produces a 422.
            // -----------------------------------------------------------------
            'component' => [
                'required',
                Rule::enum(ObligationComponent::class),
            ],

            // -----------------------------------------------------------------
            // Amount — required, positive decimal, at most 4 decimals.
            //
            // The regex accepts an UNSIGNED decimal string. A sign is
            // deliberately not permitted: an obligation's direction is a
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
            // Business timestamps — optional ISO 8601 date-times.
            //
            // No ordering constraint between `occurred_at` and `due_at`
            // is enforced. A retrospective obligation with a past due
            // date is a legitimate case (2B.16 §"What this service
            // deliberately does NOT do").
            // -----------------------------------------------------------------
            'occurred_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'due_at' => [
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
                        __('wallet::validation.obligation.amount.non_zero'),
                    );
                }
            }

            // -----------------------------------------------------------------
            // 2. Reference pair must be all-or-nothing.
            //
            // The check runs only when neither half has failed its own
            // format rule; a half that is malformed (e.g. an integer
            // `reference_id`) is reported by that rule, and the pairing
            // check would produce a confusing second error on the same
            // field.
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
                        __('wallet::validation.obligation.reference.pair'),
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
     * current locale. No English string is hard-coded. See the class
     * docblock §"Amharic-first error messages".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'component.required' => __('wallet::validation.obligation.component.required'),
            'component.enum'     => __('wallet::validation.obligation.component.enum'),

            'amount.required' => __('wallet::validation.obligation.amount.required'),
            'amount.string'   => __('wallet::validation.obligation.amount.string'),
            'amount.regex'    => __('wallet::validation.obligation.amount.regex'),

            'occurred_at.date' => __('wallet::validation.obligation.occurred_at.date'),
            'due_at.date'      => __('wallet::validation.obligation.due_at.date'),

            'reference_type.string' => __('wallet::validation.obligation.reference_type.string'),
            'reference_type.max'    => __('wallet::validation.obligation.reference_type.max'),
            'reference_id.string'   => __('wallet::validation.obligation.reference_id.string'),
            'reference_id.max'      => __('wallet::validation.obligation.reference_id.max'),

            'description.string' => __('wallet::validation.obligation.description.string'),
            'description.max'    => __('wallet::validation.obligation.description.max'),
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
            'component'      => __('wallet::attributes.obligation.component'),
            'amount'         => __('wallet::attributes.obligation.amount'),
            'occurred_at'    => __('wallet::attributes.obligation.occurred_at'),
            'due_at'         => __('wallet::attributes.obligation.due_at'),
            'reference_type' => __('wallet::attributes.obligation.reference_type'),
            'reference_id'   => __('wallet::attributes.obligation.reference_id'),
            'description'    => __('wallet::attributes.obligation.description'),
        ];
    }
}