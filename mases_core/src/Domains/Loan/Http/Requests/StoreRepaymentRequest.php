<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * StoreRepaymentRequest
 *
 * The HTTP request validator for the loan-repayment endpoint:
 * `POST /loans/{loan}/repay` (A.80, `LoanRepaymentController::store()`).
 *
 * This is the most structurally complex request in the Loan domain. It
 * accepts a repayment amount, an ordered list of allocation
 * instructions, and four optional supplementary fields. It performs one
 * cross-field validation — that the sum of the allocation amounts equals
 * the repayment amount — which no other Loan request performs.
 *
 * =============================================================================
 * Scope — shape validation and one cross-field sum check
 * =============================================================================
 * This request validates:
 *
 *   - The SHAPE of the top-level fields (`amount`, `received_at`,
 *     `reference_type`, `reference_id`, `description`).
 *
 *   - The SHAPE of each allocation instruction
 *     (`allocations.*.obligation_id`, `allocations.*.amount`).
 *
 *   - ONE cross-field rule: the sum of the `allocations.*.amount`
 *     values, computed with BCMath at scale 4, must equal `amount`. This
 *     mirrors `LoanRepaymentService::assertAllocationsWellFormed()`
 *     (A.31), which enforces the same rule at the service boundary. The
 *     request produces a field-keyed 422 through the standard validation
 *     pipeline; the service produces the same error for a programmatic
 *     caller that bypasses the HTTP layer.
 *
 * The request does NOT perform:
 *
 *   - Existence checks. Whether `{loan}` references an existing loan in
 *     the authenticated tenant is resolved by
 *     `LoanRepaymentController::store()` (A.80) via
 *     `LoanRepository::findForTenantOrFail()` (A.55), which produces a
 *     404 for a non-existent or cross-tenant loan id.
 *
 *   - Obligation-belonging checks. Whether each `obligation_id`
 *     references an obligation that belongs to the loan's Wallet AND
 *     carries `reference_type = 'Loan'` and
 *     `reference_id = (string) $loan->id` is validated by
 *     `LoanRepaymentService::assertAllocationsWellFormed()` (A.31). The
 *     service resolves each obligation through
 *     `ObligationRepository::findForWallet()` and checks its reference
 *     pair. Moving that check into the request would require the
 *     request to resolve the loan, load the Wallet, and issue an
 *     obligation lookup per instruction — logic that belongs to the
 *     service, not the HTTP boundary.
 *
 *   - Currency-equality checks. Whether the loan's currency matches the
 *     Wallet's currency (Q8) is validated by
 *     `LoanRepaymentService::record()` (A.31), which produces a 422 with
 *     a `currency` field error on a mismatch.
 *
 *   - State checks. Whether the loan is in a repayable state
 *     (`disbursed`, `active`, `in_arrears`, `restructured`) is validated
 *     by `LoanRepaymentService::assertRepayableStatus()` (A.31), which
 *     produces a 422 with a `status` field error for an illegal state.
 *
 * The separation matches the shape/service split documented on
 * `StoreLoanRequest` (A.60).
 *
 * =============================================================================
 * The allocation instruction shape
 * =============================================================================
 * The `allocations` field is a REQUIRED, NON-EMPTY, LIST-VALUED array of
 * objects, each with two fields:
 *
 *     {
 *         "obligation_id": <int >= 1>,
 *         "amount":        "<decimal string, 0..4 decimal places>"
 *     }
 *
 * The list is ordered. The service applies the instructions in the order
 * the caller supplies them; the order determines the sequence of
 * `wallet_payment_allocations` rows and of the `PaymentAllocation`
 * instances carried by the `LoanRepaymentRecorded` event (A.42).
 *
 * At least one instruction is required: the D110 freeze (Phase 2B)
 * declines to encode a universal payment-allocation priority. The
 * borrower (or the operator recording on the borrower's behalf) MUST
 * specify how the payment is to be attributed. A payload with an empty
 * `allocations` array fails the `min:1` rule.
 *
 * The instruction's `amount` values must sum EXACTLY to the top-level
 * `amount`. The sum check is performed by `withValidator()` (see below)
 * using BCMath at scale 4. A sum that is off by even a single unit in
 * the last place produces a 422 with an `allocations` field error.
 *
 * =============================================================================
 * The cross-field sum check — `withValidator()`
 * =============================================================================
 * The `withValidator()` hook runs after the field-level rules have
 * passed. It:
 *
 *   1. Returns early if the field-level rules already failed. Running
 *      the sum check on malformed input (a missing or non-array
 *      `allocations` field, a missing or non-string `amount`) would
 *      produce a confusing secondary error. The early-return guard
 *      ensures the sum error is only added when the shapes are
 *      well-formed.
 *
 *   2. Reads `amount` and `allocations` from the raw input.
 *
 *   3. Sums the allocation `amount` values with BCMath at scale 4 —
 *      NEVER with PHP's native `+` or `array_sum()`, which would
 *      introduce floating-point arithmetic into the financial
 *      pipeline.
 *
 *   4. Compares the sum with `amount` at scale 4 via `bccomp()`. A
 *      non-zero result adds a field-keyed error to `allocations` via
 *      the validator's `errors()->add()` method.
 *
 * The message for the sum-mismatch error is resolved through the Loan
 * domain's translation namespace under
 * `loan::validation.allocations.sum_mismatch`. See the class docblock of
 * `StoreLoanRequest` (A.60) §"Deployment Notes" for the namespace
 * registration contract.
 *
 * =============================================================================
 * Field reference
 * =============================================================================
 * The endpoint accepts seven top-level fields:
 *
 *   amount           REQUIRED. Decimal string with up to 4 decimal
 *                    places. The total amount of the repayment. The
 *                    strict-positivity check is deferred to the service
 *                    (A.31's `assertAmountIsWellFormed()`); this request
 *                    validates only the shape.
 *
 *   allocations      REQUIRED. Array of at least one instruction. Each
 *                    instruction has `obligation_id` (int, >= 1) and
 *                    `amount` (decimal string, up to 4 decimal places).
 *
 *   received_at      OPTIONAL. ISO 8601 date or datetime. When omitted
 *                    or null, the service uses `Carbon::now()` at the
 *                    moment of the operation.
 *
 *   reference_type   OPTIONAL. String, up to 64 characters. External
 *                    reference classifier for the payment.
 *
 *   reference_id     OPTIONAL. String, up to 64 characters. External
 *                    reference identifier for the payment.
 *
 *   description      OPTIONAL. String, up to 500 characters. Free-text
 *                    description for the Wallet payment.
 *
 * The `reference_type` and `reference_id` fields are reserved for a
 * future CR. In the frozen A.30/A.31 flow, the service (A.31) hardcodes
 * the Wallet payment's `reference_type = 'LoanRepayment'` and
 * `reference_id = (string) $loan->id`, IGNORING the
 * `$referenceType`/`$referenceId` parameters it accepts. See
 * §"Deployment Notes" (item 6) for the full rationale. The request
 * validates the two fields' shapes per the handoff §5 spec; the values
 * are silently dropped by the frozen service.
 *
 * All string fields are trimmed by `prepareForValidation()`. No case
 * normalization is applied.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * `authorize()` returns `true` unconditionally. Authorization is enforced
 * by the controller (A.80) via `Gate::authorize('repay', $loan)`, which
 * resolves through `LoanPolicy::repay()` (A.52). Same posture as A.60
 * through A.63.
 *
 * =============================================================================
 * What this request deliberately does NOT do
 * =============================================================================
 *   - It does NOT authorize. See §"Authorization".
 *
 *   - It does NOT check the loan's existence. The controller resolves
 *     `{loan}` through the tenant-scoped repository.
 *
 *   - It does NOT check the loan's state or the Wallet's existence or
 *     the currency match. The service is the authoritative boundary for
 *     all three.
 *
 *   - It does NOT check that an `obligation_id` refers to an existing
 *     obligation or to one belonging to the loan. The service resolves
 *     each instruction's obligation and produces a `ModelNotFoundException`
 *     (404) or a `ValidationException` (422) as appropriate.
 *
 *   - It does NOT enforce strict positivity of `amount` or of each
 *     instruction's `amount`. The service (A.31) enforces positivity for
 *     both; this request validates shape only. See §"Field reference".
 *
 *   - It does NOT accept an `actor_user_id` field. The actor is derived
 *     from the authenticated user by the controller.
 *
 *   - It does NOT accept a `payment_id` field. The Wallet payment's
 *     primary key is assigned by the Wallet domain when the payment is
 *     recorded; it is not supplied by the caller.
 *
 *   - It does NOT accept a `status` field. Repayments do not set the
 *     loan's status directly; the settlement detection in the service
 *     may transition the loan, but that is a consequence of the
 *     repayment, not a caller input.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D110 (allocation
 *                                                  policy),
 *                                                  B1 (reference types),
 *                                                  B2 (hybrid ledger)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C A.18                                — `Loan` (the affected
 *                                                  aggregate)
 *   Phase 2C A.31                                — `LoanRepaymentService::record()`
 *                                                  (the service that
 *                                                  consumes this request,
 *                                                  and that performs the
 *                                                  authoritative
 *                                                  cross-field and
 *                                                  domain checks)
 *   Phase 2C A.52                                — `LoanPolicy::repay()`
 *                                                  (the authorization
 *                                                  ability)
 *   Phase 2C A.55                                — `LoanRepository` (the
 *                                                  tenant-scoped lookup
 *                                                  used by the
 *                                                  controller)
 *   Phase 2C A.60–A.63                           — the sibling requests
 *   Phase 2C A.80                                — `LoanRepaymentController::store()`
 *                                                  (the consumer)
 *   Phase 2B 2B.17                               — `PaymentService`
 *   Phase 2B 2B.18                               — `AllocationService`
 *   Phase 2A 2A.22                               — `ApproveCertificateRequest`
 *                                                  (the shape precedent
 *                                                  for array-valued
 *                                                  allocations)
 *   Step 5 (manifest) A.64                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class StoreRepaymentRequest extends FormRequest
{
    /**
     * The monetary scale used for the cross-field sum check.
     *
     * Matches the DECIMAL(20,4) monetary columns on both the Loan and
     * Wallet schemas, and the scale used by every service in the Loan
     * domain.
     */
    private const FINAL_SCALE = 4;

    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('repay', $loan)`. See the class
     * docblock §"Authorization".
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The validation rules for the request.
     *
     * See the class docblock §"Field reference" and §"The allocation
     * instruction shape" for the rationale.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => [
                'required',
                'string',
                'min:16',
                'max:128',
                'regex:/\\A[A-Za-z0-9._:-]+\\z/',
            ],

            'amount' => [
                'required',
                'string',
                'decimal:0,4',
            ],

            'allocations' => [
                'required',
                'array',
                'min:1',
            ],

            'allocations.*.obligation_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'allocations.*.amount' => [
                'required',
                'string',
                'decimal:0,4',
            ],

            'received_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'reference_type' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
            ],

            'reference_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * The custom validation messages.
     *
     * Messages are resolved through the Loan domain's translation
     * namespace. See the class docblock of `StoreLoanRequest` (A.60)
     * §"Deployment Notes" for the namespace registration contract.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => __('loan::validation.amount.required'),
            'amount.string'   => __('loan::validation.amount.string'),
            'amount.decimal'  => __('loan::validation.amount.decimal'),

            'allocations.required' => __('loan::validation.allocations.required'),
            'allocations.array'    => __('loan::validation.allocations.array'),
            'allocations.min'      => __('loan::validation.allocations.min'),

            'allocations.*.obligation_id.required' => __('loan::validation.allocations.obligation_id.required'),
            'allocations.*.obligation_id.integer'  => __('loan::validation.allocations.obligation_id.integer'),
            'allocations.*.obligation_id.min'      => __('loan::validation.allocations.obligation_id.min'),

            'allocations.*.amount.required' => __('loan::validation.allocations.amount.required'),
            'allocations.*.amount.string'   => __('loan::validation.allocations.amount.string'),
            'allocations.*.amount.decimal'  => __('loan::validation.allocations.amount.decimal'),

            'received_at.date' => __('loan::validation.received_at.date'),

            'reference_type.string' => __('loan::validation.reference_type.string'),
            'reference_type.max'    => __('loan::validation.reference_type.max'),

            'reference_id.string' => __('loan::validation.reference_id.string'),
            'reference_id.max'    => __('loan::validation.reference_id.max'),

            'description.string' => __('loan::validation.description.string'),
            'description.max'    => __('loan::validation.description.max'),
        ];
    }

    /**
     * The human-readable attribute names.
     *
     * Attribute names are resolved through the Loan domain's translation
     * namespace, same as the messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount'     => __('loan::attributes.amount'),
            'allocations' => __('loan::attributes.allocations'),

            'allocations.*.obligation_id' => __('loan::attributes.allocations.obligation_id'),
            'allocations.*.amount'        => __('loan::attributes.allocations.amount'),

            'received_at'     => __('loan::attributes.received_at'),
            'reference_type'  => __('loan::attributes.reference_type'),
            'reference_id'    => __('loan::attributes.reference_id'),
            'description'     => __('loan::attributes.description'),
            'idempotency_key' => 'Idempotency-Key header',
        ];
    }

    /**
     * Attach the cross-field sum validator.
     *
     * The callback runs after the field-level rules have passed. It sums
     * the `allocations.*.amount` values with BCMath at scale 4 and
     * compares the sum against `amount` at scale 4. A mismatch adds a
     * field-keyed error to `allocations`.
     *
     * See the class docblock §"The cross-field sum check" for the full
     * rationale on the early-return guard and the BCMath discipline.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // -----------------------------------------------------------------
            // Guard: if the field-level rules already failed, do not run
            // the sum check. The check assumes well-formed input.
            // -----------------------------------------------------------------
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $amount = $this->input('amount');
            $allocations = $this->input('allocations');

            if (!is_string($amount) || !is_array($allocations)) {
                return;
            }

            // -----------------------------------------------------------------
            // Sum the allocation amounts with BCMath at scale 4.
            //
            // NEVER use PHP's native `+` operator or `array_sum()` here:
            // both would introduce floating-point arithmetic into the
            // financial pipeline. The BCMath discipline is a
            // platform-wide requirement (A9 and the handoff §3).
            // -----------------------------------------------------------------
            $sum = '0.0000';
            $shapeIsWellFormed = true;

            foreach ($allocations as $allocation) {
                if (!is_array($allocation)) {
                    $shapeIsWellFormed = false;
                    break;
                }

                $allocationAmount = $allocation['amount'] ?? null;

                if (!is_string($allocationAmount)) {
                    $shapeIsWellFormed = false;
                    break;
                }

                $sum = bcadd($sum, $allocationAmount, self::FINAL_SCALE);
            }

            if (!$shapeIsWellFormed) {
                // The field-level rules should have caught a malformed
                // instruction; the guard here is defensive.
                return;
            }

            // -----------------------------------------------------------------
            // Compare the sum against the top-level amount at scale 4.
            // -----------------------------------------------------------------
            if (bccomp($sum, $amount, self::FINAL_SCALE) === 0) {
                return;
            }

            $v->errors()->add(
                'allocations',
                __('loan::validation.allocations.sum_mismatch', [
                    'sum'    => $sum,
                    'amount' => $amount,
                ]),
            );
        });
    }

    /**
     * Normalize input before validation.
     *
     * Trims leading and trailing whitespace from the string fields.
     * Empty-after-trim values become `null` for the OPTIONAL fields, so
     * the service's null-handling is consistent regardless of whether
     * the caller submitted an empty string or omitted the field.
     *
     * The REQUIRED string fields (`amount`, each instruction's `amount`)
     * are trimmed but NOT nulled: an empty-after-trim `amount` fails the
     * `required` rule, which is the correct behavior. The normalization
     * applies to the top-level `amount` and to each instruction's
     * `amount` and `obligation_id` (the latter only to trim any
     * whitespace around a numeric string; the `integer` rule will handle
     * the conversion).
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [
            'idempotency_key' => trim((string) $this->header('Idempotency-Key', '')),
        ];

        // ---------------------------------------------------------------------
        // Top-level string fields.
        // ---------------------------------------------------------------------
        foreach (['amount', 'reference_type', 'reference_id', 'description', 'received_at'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            // Optional fields normalize empty-after-trim to null so the
            // service's null-handling is consistent.
            if ($value === '' && in_array($field, ['reference_type', 'reference_id', 'description', 'received_at'], true)) {
                $value = null;
            }

            $merged[$field] = $value;
        }

        // ---------------------------------------------------------------------
        // Per-instruction string fields.
        // ---------------------------------------------------------------------
        $allocations = $this->input('allocations');

        if (is_array($allocations)) {
            $normalizedAllocations = [];

            foreach ($allocations as $index => $allocation) {
                if (!is_array($allocation)) {
                    $normalizedAllocations[$index] = $allocation;
                    continue;
                }

                if (array_key_exists('obligation_id', $allocation) && is_string($allocation['obligation_id'])) {
                    $allocation['obligation_id'] = trim($allocation['obligation_id']);
                }

                if (array_key_exists('amount', $allocation) && is_string($allocation['amount'])) {
                    $allocation['amount'] = trim($allocation['amount']);
                }

                $normalizedAllocations[$index] = $allocation;
            }

            $merged['allocations'] = $normalizedAllocations;
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }
}