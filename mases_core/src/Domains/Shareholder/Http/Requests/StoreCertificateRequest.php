<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * StoreCertificateRequest
 *
 * Validates input for issuing a share certificate.
 *
 * =============================================================================
 * What this request produces
 * =============================================================================
 * The validated payload drives one of two CertificateService (2A.15)
 * operations, chosen by the controller based on whether `share_holding_id`
 * was supplied:
 *
 *   - With share_holding_id:
 *         CertificateService::issue(
 *             shareholder, shareClass, holding, certificateNumber, notes
 *         )
 *
 *   - Without share_holding_id:
 *         CertificateService::issueFor(
 *             shareholder, shareClass, certificateNumber, notes
 *         )
 *       The service resolves (or creates) the holding for the pair.
 *
 * Both methods create the certificate with `status = issued` and set
 * `issued_at` to now. The caller cannot override either field through this
 * endpoint.
 *
 * =============================================================================
 * Why `status` is not accepted
 * =============================================================================
 * Certificates are issued through a single state transition: nonexistent →
 * issued. The remaining states (`draft`, `cancelled`, `replaced`) are
 * reachable only through their own operations:
 *
 *     draft      — not reachable through CertificateService in Phase 2A
 *                  (see 2A.15, F-2A15-8)
 *     cancelled  — reachable via CertificateService::cancel() (2A.15)
 *     replaced   — reachable via CertificateService::replace() (2A.15)
 *
 * Because issuance is definitionally the creation of an `issued`
 * certificate, this request does not accept a `status` field. A caller
 * that supplies one produces no effect: the field is not part of the
 * validated payload, and the controller does not forward it.
 *
 * If a stricter contract is desired (reject unknown fields with a 422),
 * a Change Request can add `'status' => 'prohibited'`. Until then, silent
 * drop is consistent with the pattern used by 2A.16 and 2A.17 for
 * `tenant_id` and `id`.
 *
 * =============================================================================
 * Certificate number
 * =============================================================================
 * `certificate_number` is nullable (pre-flight answer). When supplied, it
 * is a plain string. When omitted, the certificate has no number.
 *
 * The request does NOT validate uniqueness. Uniqueness is enforced by:
 *
 *   1. The `UNIQUE` index on `share_certificates.certificate_number`
 *      (migration 2A.41).
 *
 *   2. `CertificateService::assertCertificateNumberIsAvailable()`
 *      (2A.15), which checks the value inside the issuance transaction
 *      and raises a friendly ValidationException before the DB
 *      constraint fires.
 *
 * The request validates only the field shape: string, non-empty when
 * present, max length 64 (matching the column). A caller who sends an
 * empty string receives an error because the column contract requires
 * `NULL` for "no number" — never `''`.
 *
 * =============================================================================
 * Notes
 * =============================================================================
 * `notes` is an optional free-text field. The column is TEXT
 * (migration 2A.41), which in MySQL holds up to 65,535 bytes. The request
 * caps the value at 2,000 characters — a defensive limit that keeps the
 * payload small enough for a request body, and discourages using the
 * notes field as a document store. If the business requires longer notes,
 * a Change Request widens the cap.
 *
 * =============================================================================
 * Target identification
 * =============================================================================
 * `shareholder_id` and `share_class_id` are required. Together they
 * identify the pair. `share_holding_id` is optional; when supplied, the
 * controller uses it to construct the specific ShareHolding the
 * certificate references, bypassing the service's holding resolution.
 *
 * If `share_holding_id` is supplied AND it does not belong to the given
 * `shareholder_id` / `share_class_id` pair, the service
 * (`assertHoldingMatchesShareholderAndClass`, 2A.15) rejects the
 * operation. This request does not perform that check, because the check
 * requires reading the ShareHolding row and is more appropriately a
 * service-layer concern.
 *
 * =============================================================================
 * No authorization here
 * =============================================================================
 * `authorize()` returns true. The controller verifies the caller holds
 * the `certificate.issue` permission and invokes the ShareholderPolicy
 * (2A.29) on the target shareholder. CertificateService does not perform
 * authorization either — it trusts that the caller has passed policy.
 *
 * =============================================================================
 * Amharic-first error messages
 * =============================================================================
 * Same policy as 2A.16 through 2A.19: no hard-coded English strings. Every
 * message is a translation key resolved through Laravel's translator in
 * the current locale.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests
 */
final class StoreCertificateRequest extends FormRequest
{
    /**
     * Maximum length of `certificate_number` per migration 2A.41.
     */
    private const MAX_CERTIFICATE_NUMBER_LENGTH = 64;

    /**
     * Defensive maximum length for `notes`.
     *
     * The column is TEXT (up to 65,535 bytes on MySQL). This cap is
     * intentionally much smaller so that a client cannot submit a
     * multi-megabyte request body. A future CR can widen it.
     */
    private const MAX_NOTES_LENGTH = 2000;

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
     * `certificate_number` and `notes` are trimmed of leading and trailing
     * whitespace. Empty strings are converted to null so that the "no
     * number" and "no notes" cases are consistently represented as null
     * throughout the pipeline.
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

            // Convert empty strings to null for the two optional text fields.
            // This makes the "absent or empty" case indistinguishable from
            // "explicitly null", which is the correct interpretation for
            // both `certificate_number` and `notes`.
            if (
                $trimmed === ''
                && in_array($key, ['certificate_number', 'notes'], true)
            ) {
                $merged[$key] = null;
                continue;
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Target — the shareholder/class pair is required.
            // -----------------------------------------------------------------
            'shareholder_id' => [
                'required',
                'integer',
                'min:1',
                'exists:shareholders,id',
            ],
            'share_class_id' => [
                'required',
                'integer',
                'min:1',
                'exists:share_classes,id',
            ],

            // -----------------------------------------------------------------
            // Target — optional explicit holding reference.
            // -----------------------------------------------------------------
            'share_holding_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:share_holdings,id',
            ],

            // -----------------------------------------------------------------
            // Certificate number — optional, no empty string.
            // -----------------------------------------------------------------
            'certificate_number' => [
                'sometimes',
                'nullable',
                'string',
                'min:1',
                'max:' . self::MAX_CERTIFICATE_NUMBER_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Notes — optional free text.
            // -----------------------------------------------------------------
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_NOTES_LENGTH,
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Currently no additional checks are needed — the base rules cover
     * every constraint this endpoint enforces. The method is retained so
     * that a future CR (e.g. cross-field checks between
     * `share_holding_id` and the pair) has an obvious extension point.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        // No additional checks in Phase 2A. See the class docblock for the
        // rationale on `share_holding_id` validation by the service.
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
            'shareholder_id.required' => __('shareholder::validation.certificate.shareholder_id.required'),
            'shareholder_id.exists'   => __('shareholder::validation.certificate.shareholder_id.exists'),

            'share_class_id.required' => __('shareholder::validation.certificate.share_class_id.required'),
            'share_class_id.exists'   => __('shareholder::validation.certificate.share_class_id.exists'),

            'share_holding_id.exists' => __('shareholder::validation.certificate.share_holding_id.exists'),

            'certificate_number.max'  => __('shareholder::validation.certificate.certificate_number.max'),
            'certificate_number.min'  => __('shareholder::validation.certificate.certificate_number.min'),

            'notes.max' => __('shareholder::validation.certificate.notes.max'),
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
            'shareholder_id'     => __('shareholder::attributes.certificate.shareholder_id'),
            'share_class_id'     => __('shareholder::attributes.certificate.share_class_id'),
            'share_holding_id'   => __('shareholder::attributes.certificate.share_holding_id'),
            'certificate_number' => __('shareholder::attributes.certificate.certificate_number'),
            'notes'              => __('shareholder::attributes.certificate.notes'),
        ];
    }
}