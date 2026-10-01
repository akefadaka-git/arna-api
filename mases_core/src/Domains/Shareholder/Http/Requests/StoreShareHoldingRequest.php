<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\HoldingDirection;

/**
 * StoreShareHoldingRequest
 *
 * Validates input for recording a share-holding entry.
 *
 * =============================================================================
 * What this request represents
 * =============================================================================
 * A share-holding entry is a single, append-only ledger row that changes
 * the current quantity of a ShareHolding. Every entry records:
 *
 *   - a direction (in / out),
 *   - a magnitude (positive whole number),
 *   - an entry type (one of the five direct types),
 *   - optional reference metadata (type, id, description),
 *   - an occurrence time.
 *
 * The service computes the signed quantity, the before and after balances,
 * and persists both the entry and the updated projection inside one
 * transaction (D16-CORE).
 *
 * This request validates only the SHAPE of the input. Every domain
 * invariant (negative balance prohibition, whole-number quantity, entry-
 * type membership) is re-checked by ShareHoldingService (2A.14) before the
 * transaction commits.
 *
 * =============================================================================
 * Target identification
 * =============================================================================
 * The request accepts two mutually exclusive modes for identifying the
 * target holding:
 *
 *   Mode 1 — direct holding reference:
 *       { "share_holding_id": 42, "direction": "in", "quantity": 100, ... }
 *
 *   Mode 2 — pair reference:
 *       { "shareholder_id": 7, "share_class_id": 2,
 *         "direction": "in", "quantity": 100, ... }
 *
 * Exactly one mode must be supplied. Supplying both is an error
 * ("please provide either share_holding_id or the pair"); supplying
 * neither is an error ("please provide a target"). The controller uses
 * the presence of `share_holding_id` to decide whether to call
 * `ShareHoldingService::recordEntry()` (which takes a ShareHolding) or
 * `recordEntryFor()` (which takes Shareholder + ShareClass).
 *
 * The mutual-exclusion check runs in `withValidator()`, because Laravel's
 * declarative rules cannot express "exactly one of A or (B and C)".
 *
 * =============================================================================
 * Direction and quantity (D6)
 * =============================================================================
 * `direction` is one of `in` or `out`. The `neutral` case from
 * HoldingDirection (2A.4) is NOT accepted here: a neutral entry has no
 * effect and the ledger's purpose is to record effects. If a future
 * feature needs informational rows, a CR will add them.
 *
 * `quantity` is a strictly positive whole number. Laravel's `integer` rule
 * accepts JSON integers and numeric strings that parse to integers; it
 * rejects floats. Combined with `min:1`, this enforces D6's "no fractional
 * shares" rule at the request boundary. The service re-checks the value
 * after it is applied via `HoldingDirection::applyTo()`, so a caller
 * cannot bypass the check by sending a value in an unexpected form.
 *
 * =============================================================================
 * Entry type (D16-R3, D16-R8)
 * =============================================================================
 * The five permitted entry types are:
 *
 *     subscription
 *     transfer_in
 *     transfer_out
 *     allocation
 *     adjustment
 *
 * The sixth type, `reversal`, is NOT accepted here. A reversal must be
 * constructed by `ShareHoldingService::reverseEntry()`, which links the new
 * entry to the original via `reversed_entry_id`. Reversing an arbitrary
 * entry is a distinct operation with its own endpoint and its own
 * authorization. If a caller mistakenly sends `reversal` to this endpoint,
 * the request produces a clear error pointing them at the correct flow.
 *
 * =============================================================================
 * Reference metadata
 * =============================================================================
 * `reference_type` and `reference_id` together form an optional pointer
 * from the ledger entry back to the business operation that caused it. For
 * example:
 *
 *     reference_type: "ShareTransfer"
 *     reference_id:   "42"
 *
 * The values are free strings from the request layer's perspective; the
 * service does not validate them against any enum or table. This is
 * deliberate — the exact vocabulary of reference types evolves with the
 * business operations, and pinning it down in Phase 2A would require a CR
 * for every new operation.
 *
 * `description` is an optional human-readable note. It is stored on the
 * entry as-is and rendered by the resource layer (2A.27) as plain text.
 *
 * =============================================================================
 * Occurrence time
 * =============================================================================
 * `occurred_at` is an optional datetime string. When omitted, the service
 * defaults to `now()`. When supplied, it is used verbatim as the entry's
 * `occurred_at` — the ledger orders by this column, so a backfill can
 * insert historical entries without disturbing the current projection.
 *
 * The request does not enforce an upper bound (a caller could send a
 * future timestamp). The service does not reject it either. This is a
 * known limitation; a future CR can add a bound if the business requires
 * it (see F-2A14-9).
 *
 * =============================================================================
 * Fields the caller does NOT control
 * =============================================================================
 * The following fields are computed or sourced server-side and are NOT
 * accepted by this request, even if a caller sends them:
 *
 *     balance_before    — computed by the service inside the transaction
 *     balance_after     — computed by the service inside the transaction
 *     created_at        — set by the service at insertion time
 *     created_by        — set from auth()->id() by the service
 *     reversed_entry_id — set only by reverseEntry(), never here
 *
 * The service's whitelist drops unknown keys, so a caller sending these
 * receives no error but also produces no effect. The request could add
 * 'prohibited' rules to reject them, but silent drop is the current Phase
 * 2A convention (same as 2A.16 and 2A.17 for tenant_id and id).
 *
 * =============================================================================
 * No authorization here
 * =============================================================================
 * `authorize()` returns true. The controller verifies the caller holds the
 * `share_holding.entry` permission (see the RBAC plan in the frozen
 * manifest) before calling the service. The policy layer enforces
 * ownership/tenant scoping on the target holding.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests
 */
final class StoreShareHoldingRequest extends FormRequest
{
    /**
     * Entry types permitted by this endpoint (D16-R8 direct subset).
     *
     * `reversal` is deliberately absent — see the class docblock.
     *
     * @var list<string>
     */
    private const DIRECT_ENTRY_TYPES = [
        'subscription',
        'transfer_in',
        'transfer_out',
        'allocation',
        'adjustment',
    ];

    /**
     * Maximum length of `reference_type` per migration 2A.40.
     */
    private const MAX_REFERENCE_TYPE_LENGTH = 64;

    /**
     * Maximum length of `reference_id` per migration 2A.40.
     */
    private const MAX_REFERENCE_ID_LENGTH = 64;

    /**
     * Maximum length of `description` per migration 2A.40.
     */
    private const MAX_DESCRIPTION_LENGTH = 500;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Always true — see the class docblock.
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
     * Trim whitespace from string inputs.
     *
     * String enumeration inputs (`direction`, `entry_type`) are also
     * lower-cased so that a caller typing `"IN"` or `"Subscription"`
     * receives the same treatment as one typing the canonical lowercase
     * value. This mirrors the enum's backed values (2A.4).
     *
     * Non-string inputs are passed through unchanged.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach ($this->all() as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($key === 'direction' || $key === 'entry_type') {
                $trimmed = strtolower($trimmed);
            }

            $merged[$key] = $trimmed;
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
     * Both target modes are declared as `sometimes` / `nullable` so that a
     * caller can supply either. The mutual-exclusion check runs in
     * `withValidator()`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Target — Mode 1 (direct holding reference)
            // -----------------------------------------------------------------
            'share_holding_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:share_holdings,id',
            ],

            // -----------------------------------------------------------------
            // Target — Mode 2 (shareholder + share class pair)
            // -----------------------------------------------------------------
            'shareholder_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:shareholders,id',
            ],
            'share_class_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:share_classes,id',
            ],

            // -----------------------------------------------------------------
            // Direction (In / Out; Neutral not permitted)
            // -----------------------------------------------------------------
            'direction' => [
                'required',
                'string',
                Rule::in([
                    HoldingDirection::In->value,
                    HoldingDirection::Out->value,
                ]),
            ],

            // -----------------------------------------------------------------
            // Quantity — positive whole number (D6)
            // -----------------------------------------------------------------
            'quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            // -----------------------------------------------------------------
            // Entry type (direct subset — no `reversal`)
            // -----------------------------------------------------------------
            'entry_type' => [
                'required',
                'string',
                Rule::in(self::DIRECT_ENTRY_TYPES),
            ],

            // -----------------------------------------------------------------
            // Reference metadata
            // -----------------------------------------------------------------
            'reference_type' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_REFERENCE_TYPE_LENGTH,
            ],
            'reference_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_REFERENCE_ID_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Description
            // -----------------------------------------------------------------
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_DESCRIPTION_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Occurrence time
            // -----------------------------------------------------------------
            'occurred_at' => [
                'sometimes',
                'nullable',
                'date',
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Adds the "exactly one target mode" check that cannot be expressed
     * declaratively.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateTargetMode($v);
            $this->validateReversalNotSupplied($v);
        });
    }

    /**
     * Assert that exactly one target identification mode was supplied.
     *
     * Three error cases are distinguished so the caller receives a precise
     * message:
     *
     *   1. Both modes supplied → "provide either share_holding_id or the
     *      shareholder/class pair, not both."
     *
     *   2. Neither mode supplied → "provide a target."
     *
     *   3. Only `shareholder_id` but not `share_class_id` (or vice versa) →
     *      "the pair must be supplied together."
     *
     * @param Validator $validator
     * @return void
     */
    private function validateTargetMode(Validator $validator): void
    {
        $hasHoldingId       = $this->filled('share_holding_id');
        $hasShareholderId   = $this->filled('shareholder_id');
        $hasShareClassId    = $this->filled('share_class_id');

        $hasPair = $hasShareholderId && $hasShareClassId;

        // Case 1: both modes supplied.
        if ($hasHoldingId && $hasPair) {
            $validator->errors()->add(
                'share_holding_id',
                __('shareholder::validation.share_holding.target_ambiguous'),
            );

            return;
        }

        // Case 2: neither mode supplied.
        if (!$hasHoldingId && !$hasPair) {
            // Sub-case 2a: only one side of the pair supplied.
            if ($hasShareholderId xor $hasShareClassId) {
                $validator->errors()->add(
                    'shareholder_id',
                    __('shareholder::validation.share_holding.target_pair_incomplete'),
                );

                return;
            }

            // Sub-case 2b: nothing at all supplied.
            $validator->errors()->add(
                'share_holding_id',
                __('shareholder::validation.share_holding.target_required'),
            );
        }
    }

    /**
     * Assert that the caller did not attempt to create a reversal through
     * this endpoint.
     *
     * Reversals are created only by `ShareHoldingService::reverseEntry()`,
     * which links the new row to the original through `reversed_entry_id`.
     * A caller that sent `entry_type = "reversal"` here has confused the
     * two endpoints. The base `Rule::in` on `entry_type` would already
     * reject the value with a generic "invalid entry type" message; this
     * check produces a more helpful pointer.
     *
     * If `entry_type` itself failed the base rule because it is not even a
     * string or is empty, we skip this check — the base rule already
     * produced an error and the extra message would only add noise.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateReversalNotSupplied(Validator $validator): void
    {
        if ($this->input('entry_type') !== 'reversal') {
            return;
        }

        // The base Rule::in already rejected `reversal`; this message is
        // added in addition, not instead. To avoid duplicating the error
        // for the same field, we only add the message if the base rule did
        // not already produce one.
        if (!$validator->errors()->has('entry_type')) {
            $validator->errors()->add(
                'entry_type',
                __('shareholder::validation.share_holding.reversal_not_allowed_here'),
            );

            return;
        }

        // Base rule fired. Augment the message with the correct-flow hint
        // by replacing the field's messages array with a single, clear
        // message.
        $validator->errors()->add(
            'entry_type',
            __('shareholder::validation.share_holding.reversal_not_allowed_here'),
        );
    }

    // =========================================================================
    // Messages and attributes
    // =========================================================================

    /**
     * Get the custom validation messages.
     *
     * Every message is a translation key resolved via `__()` in the current
     * locale. No English string is hard-coded.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'share_holding_id.exists' => __('shareholder::validation.share_holding.share_holding_id.exists'),
            'shareholder_id.exists'   => __('shareholder::validation.share_holding.shareholder_id.exists'),
            'share_class_id.exists'   => __('shareholder::validation.share_holding.share_class_id.exists'),

            'direction.required' => __('shareholder::validation.share_holding.direction.required'),
            'direction.in'       => __('shareholder::validation.share_holding.direction.in'),

            'quantity.required' => __('shareholder::validation.share_holding.quantity.required'),
            'quantity.integer'  => __('shareholder::validation.share_holding.quantity.integer'),
            'quantity.min'      => __('shareholder::validation.share_holding.quantity.min'),

            'entry_type.required' => __('shareholder::validation.share_holding.entry_type.required'),
            'entry_type.in'       => __('shareholder::validation.share_holding.entry_type.in'),

            'occurred_at.date' => __('shareholder::validation.share_holding.occurred_at.date'),
        ];
    }

    /**
     * Get human-readable field names for error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'share_holding_id' => __('shareholder::attributes.share_holding.share_holding_id'),
            'shareholder_id'   => __('shareholder::attributes.share_holding.shareholder_id'),
            'share_class_id'   => __('shareholder::attributes.share_holding.share_class_id'),
            'direction'        => __('shareholder::attributes.share_holding.direction'),
            'quantity'         => __('shareholder::attributes.share_holding.quantity'),
            'entry_type'       => __('shareholder::attributes.share_holding.entry_type'),
            'reference_type'   => __('shareholder::attributes.share_holding.reference_type'),
            'reference_id'     => __('shareholder::attributes.share_holding.reference_id'),
            'description'      => __('shareholder::attributes.share_holding.description'),
            'occurred_at'      => __('shareholder::attributes.share_holding.occurred_at'),
        ];
    }

    // =========================================================================
    // Public helpers for the controller
    // =========================================================================

    /**
     * The HoldingDirection enum case derived from the validated `direction`.
     *
     * The controller uses this to build the call to
     * ShareHoldingService::recordEntry() or recordEntryFor(). The enum case
     * is guaranteed to be non-null once the request's validation has
     * succeeded — the base rule guarantees the value is one of `in` or
     * `out`, and both map to non-null cases.
     *
     * @return HoldingDirection
     */
    public function holdingDirection(): HoldingDirection
    {
        return HoldingDirection::from((string) $this->input('direction'));
    }
}