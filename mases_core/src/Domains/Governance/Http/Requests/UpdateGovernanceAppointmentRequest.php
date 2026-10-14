<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 11 of 31
 * =============================================================================
 *
 * File:            Http/Requests/UpdateGovernanceAppointmentRequest.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (update request)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `FormRequest` validated the payload of
 *   a `PATCH /api/governance/appointments/{governanceAppointment}`
 *   (or `PUT`) request. A controller action that wanted to accept
 *   an appointment update would have had to inline its own
 *   validation — the pattern the platform's convention explicitly
 *   rejects.
 *
 * After:
 *   One file that declares the canonical validation rules for
 *   updating a `GovernanceAppointment`. The request is a
 *   `FormRequest` and follows the conventions the sibling update
 *   requests in this domain established at G2a and G2b.
 *
 *   DIFFERENCE FROM A STORE REQUEST: every rule is `sometimes`. An
 *   update request never requires a field to be present — a client
 *   may send any subset of the mutable fields, and the controller
 *   passes the validated subset to the service. The service merges
 *   the subset over the current row and re-validates the resulting
 *   post-update state.
 *
 *   What the request validates (all rules `sometimes`):
 *
 *     governance_body_id      —  integer, min 1 (immutable)
 *     governance_position_id  —  nullable integer, min 1 (immutable)
 *     governance_term_id      —  nullable integer, min 1 (immutable)
 *     status                  —  string (rejected by the service)
 *     instrument_type         —  string, one of the five enum values
 *     instrument_reference    —  nullable string, max 191
 *     instrument_date         —  nullable date
 *     effective_date          —  date
 *     appointment_mode        —  string, one of the four enum values
 *     title_am                —  nullable string, max 150
 *     title_en                —  nullable string, max 150
 *     notes_am                —  nullable string, max 500
 *     notes_en                —  nullable string, max 500
 *
 * =============================================================================
 * WHY THE IMMUTABLE FKs AND `status` ARE DECLARED AS SHAPE RULES
 * =============================================================================
 * The service (`GovernanceAppointmentService`, G2c file 7 of 31)
 * refuses three classes of update:
 *
 *   · A change to `governance_body_id`, `governance_position_id`, or
 *     `governance_term_id` — those three FK columns are immutable
 *     (INV-2, INV-4, INV-6 in the service's docblock).
 *
 *   · A change to `status` — the state machine is expressed through
 *     the service's `activate()`, `revoke()`, and `supersede()`
 *     methods, never through `update()`.
 *
 * Each refusal is implemented as an invariant check inside the
 * service's `normaliseForUpdate()` method. That check runs against
 * the keys present in the validated payload. If this request did
 * NOT declare a rule for one of those four fields, Laravel's
 * `validated()` call would STRIP the key from the payload before
 * the service ever saw it — and the service's invariant check would
 * be UNREACHABLE.
 *
 * The consequence of leaving these keys undeclared:
 *
 *   · A caller that sends `{"governance_body_id": 999}` (a
 *     different body) would have the key stripped. The service
 *     would receive no `governance_body_id`, would interpret the
 *     update as "no change to the body", and would persist the
 *     other fields. The row's body would silently remain the
 *     original one. The caller would believe the body had been
 *     changed — but no error and no change. A silent
 *     non-acceptance is strictly worse than an explicit refusal.
 *
 *   · A caller that sends `{"status": "active"}` would have the key
 *     stripped. The service would receive no `status`, would skip
 *     its rejection branch, and would persist the other fields
 *     while leaving status unchanged. The caller would believe the
 *     appointment had been activated — but it had not. A future
 *     reviewer looking at the audit trail would see a field update
 *     that never touched the status, and would have no error to
 *     explain why.
 *
 * Both scenarios were the SUBSTANTIVE bug fixed in G2b by amending
 * `UpdateGovernancePositionRequest` (G2b file 8 of 27) and
 * `UpdateGovernanceTermRequest` (G2b file 20 of 27) to declare
 * shape rules for their own immutable FKs. This request ships with
 * the correction from the start.
 *
 * The four shape rules are:
 *
 *     'governance_body_id'     => ['sometimes', 'integer', 'min:1'],
 *     'governance_position_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
 *     'governance_term_id'     => ['sometimes', 'nullable', 'integer', 'min:1'],
 *     'status'                 => ['sometimes', 'string'],
 *
 * Each is deliberately PERMISSIVE — the request accepts any
 * well-formed value for the field, and the service refuses the ones
 * that would violate an invariant. The request's job is to make the
 * key survive `validated()`; the service's job is to refuse the
 * illegal values. This separation is what keeps the invariant
 * enforcement in one place.
 *
 * A future reader who is tempted to remove these four rules — "they
 * only ever fail; why declare them?" — must read this section
 * first. Removing them does NOT remove the failure mode; it
 * removes the ERROR SIGNAL, turning an explicit 422 into a silent
 * no-op. See the file-level change-verification block
 * §"The immutable-FK invariant" in
 * `UpdateGovernancePositionRequest` for the same reasoning applied
 * to the position entity.
 *
 * =============================================================================
 * The `governance_position_id` and `governance_term_id` nullable shape
 * =============================================================================
 * The two optional FK columns are nullable in the schema. A caller
 * may explicitly send `null` to clear the target position or term
 * on an appointment that previously had one — but only when the
 * value is unchanged from the current state. The service's
 * invariant check treats `null` and the current value as follows:
 *
 *   · `null` sent, current value is `null`       → match, key dropped.
 *   · `null` sent, current value is a non-null id → mismatch, 422.
 *   · id sent, current value is `null`           → mismatch, 422.
 *   · id sent, current value is the same id      → match, key dropped.
 *   · id sent, current value is a different id   → mismatch, 422.
 *
 * The request declares the shape (nullable integer, min 1) but does
 * not attempt to enforce the match; that is the service's job, and
 * it requires reading the current row. See the file-level
 * change-verification block §"WHY THE IMMUTABLE FKs AND status ARE
 * DECLARED AS SHAPE RULES" above.
 *
 * =============================================================================
 * The date ordering check — withValidator()
 * =============================================================================
 * The cross-field check is:
 *
 *     effective_date >= instrument_date
 *
 * evaluated against the POST-update state. The check is more subtle
 * than its store-side counterpart because on an update, EITHER
 * date may be unchanged. The validator must compare the incoming
 * `instrument_date` (if present) or the row's current
 * `instrument_date` (if not) against the incoming `effective_date`
 * (if present) or the row's current `effective_date` (if not).
 *
 * The row's current values are resolved from the route's model
 * binding via `$this->route('governanceAppointment')`. If the model
 * cannot be resolved — a rare case, because the route's
 * `whereNumber` constraint and the controller's tenant cross-check
 * ensure the model is always present when the request runs — the
 * cross-field check is skipped, and the service's own check (INV-8)
 * catches the violation. Skipping the request-side check in that
 * edge case is not a correctness loss; it merely defers the error
 * to the service, which produces the same 422.
 *
 * =============================================================================
 * authorize() returns true unconditionally
 * =============================================================================
 * Same rationale as the store request. Authorization is the
 * controller's concern; the policy is what refuses an unauthorized
 * caller. See the store request's file-level change-verification
 * block §"authorize() returns true unconditionally".
 *
 * =============================================================================
 * prepareForValidation() trims strings
 * =============================================================================
 * Same set of fields as the store request:
 *
 *     instrument_reference, title_am, title_en, notes_am, notes_en
 *
 * A whitespace-only string is normalised to `null`. This matters
 * for the same reason as in the store request: the model's
 * `hasTitle()` helper and the service's bilingual presence checks
 * treat whitespace-only as empty.
 *
 * The two enum-typed fields (`instrument_type`,
 * `appointment_mode`) are NOT trimmed. A client that sends
 * `" elected "` should receive a shape error, not a silent
 * normalisation that hides the input error.
 *
 * =============================================================================
 * Amharic messages and attribute names
 * =============================================================================
 * Same convention as the store request. Inline messages and
 * attribute names live in this file's `messages()` and
 * `attributes()` methods.
 *
 * The same two native-speaker review items flagged in the store
 * request apply here (`instrument_type` → `የመሣሪያ ዓይነት` and
 * `instrument_reference` → `የመሣሪያ ማጣቀሻ`). The two labels are
 * duplicated across the store and update requests deliberately:
 * the domain has no centralised validation-message catalogue, and
 * the two requests share the identical field vocabulary. If a
 * future CR introduces a catalogue, both files' messages and
 * attributes sections migrate together.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceAppointmentRequest.php
 *
 * The directory already exists.
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Rules declared:            13 (all `sometimes`)
 * Shape rules for immutable FKs: 3
 * Shape rule for status:     1
 * Cross-field checks:        1 (date ordering, post-update)
 * Inline messages declared:  every rule
 * Inline attributes declared: every rule
 * =============================================================================
 */

/*
 * =============================================================================
 * A3 CANDIDATE — APPLIED — NOT TESTED
 * =============================================================================
 *
 * RP-02 — cross-tenant validation isolation (request-level; appointment
 * half of the pair).
 *
 * Relative to the G2c v1.0.0 file above, this A3 candidate makes exactly
 * three changes:
 *
 *   1. Adds `use Illuminate\Database\Eloquent\ModelNotFoundException;`
 *      to the import block. `GovernanceAppointment` is already imported;
 *      no other import changes.
 *
 *   2. Replaces the `authorize()` method's docblock and body. The method
 *      no longer returns unconditional `true`. It verifies that the
 *      route-bound appointment's `tenant_id` equals the tenant resolved
 *      for the request. On mismatch — or when no tenant is resolvable —
 *      it throws `ModelNotFoundException`, which the canonical renderer
 *      maps to 404 `RESOURCE_NOT_FOUND`.
 *
 *   3. Adds a pointer line to the class docblock and bumps `@version`
 *      from `1.0.0` to `1.1.0-a3-candidate`.
 *
 * WHY THIS IS REQUIRED
 * -----------------------------------------------------------------------------
 * Laravel's FormRequest lifecycle runs `authorize()` BEFORE `rules()`
 * and BEFORE `withValidator()`. Before A3, `authorize()` returned
 * unconditional `true`, so the request proceeded directly into
 * `withValidator()`, which reads the route-bound appointment via
 * `$this->route('governanceAppointment')`. Route-model binding has no
 * tenant scope (verified: `GovernanceAppointment` uses only
 * `HasFactory` and `SoftDeletes`), so a cross-tenant id resolves at
 * binding and reaches `withValidator()`.
 *
 * The cross-field check compares the incoming payload against the
 * cross-tenant row's `instrument_date` and `effective_date`. A caller
 * who submits a payload that would violate the date-ordering rule
 * against the cross-tenant row receives a 422; a caller who submits a
 * payload that satisfies the rule receives a 404 from the controller's
 * `assertTenantOwnership()`. The two cases are distinguishable, and
 * the caller can therefore probe the existence and rough state of
 * appointment rows in other tenants.
 *
 * The fix: verify tenant ownership in `authorize()`. When the model's
 * `tenant_id` does not equal the resolved tenant, throw
 * `ModelNotFoundException`. That exception is produced before any
 * cross-field validator runs; the caller receives the same 404
 * envelope as for a nonexistent id.
 *
 * WHY THIS IS THE NARROWEST CORRECTION
 * -----------------------------------------------------------------------------
 * The alternative — a `resolveRouteBinding()` override on the
 * `GovernanceAppointment` model — is broader in scope: it affects every
 * binding of the model, including future routes and any route outside
 * the governance domain. The request-level fix affects only the two
 * routes that use this request class (`PATCH` and `PUT` on
 * `appointments/{governanceAppointment}`). The two `show` routes do
 * not use this FormRequest; their controllers already produce 404 for
 * cross-tenant ids via `assertTenantOwnership()`, so they do not need
 * a fix. The request-level correction leaves them untouched.
 *
 * THE STALE HEADER SECTION — DELIBERATELY NOT REWRITTEN
 * -----------------------------------------------------------------------------
 * The pre-A3 change-verification block above contains a section titled
 * §"authorize() returns true unconditionally". After this A3 change,
 * that section is factually superseded for this file — the method no
 * longer returns unconditional `true`.
 *
 * The section is preserved verbatim per the A3 mandate to preserve
 * existing documentation. This A3 CANDIDATE block is the authoritative
 * note recording the supersession. A future documentation-only CR may
 * rewrite the section header to reflect the new behaviour.
 *
 * WHAT DOES NOT CHANGE
 * -----------------------------------------------------------------------------
 *   · Every other method: `prepareForValidation()`, `rules()`,
 *     `withValidator()`, `messages()`, `attributes()`.
 *   · Every rule declared in `rules()` — thirteen `sometimes` rules
 *     including the four shape rules for the immutable FKs and `status`.
 *   · The `withValidator()` cross-field date-ordering check. It is
 *     preserved unchanged; it is simply now unreachable for
 *     cross-tenant ids because `authorize()` refuses them first.
 *   · Every import except the one addition.
 *   · Every docblock except the `authorize()` method's.
 *   · Every Amharic message and attribute name.
 *   · Every code line outside `authorize()`.
 *
 * COMPANION FILES IN THIS A3 BATCH
 * -----------------------------------------------------------------------------
 *   · Http/Requests/UpdateGovernanceAppointmentRequest.php   (this file)
 *   · Http/Requests/UpdateGovernanceMembershipRequest.php    (companion —
 *     pending delivery of the pre-A3 source or full-file reproduction)
 *
 * The two requests are the only files RP-02 needs to change if the
 * Owner selects the request-level correction. The model-wide
 * `resolveRouteBinding()` override candidates delivered earlier in
 * this A3 batch remain available as the alternative, but are not part
 * of this narrower fix.
 *
 * DEPENDENCY
 * -----------------------------------------------------------------------------
 * The override reads `mases_tenant_id()` from the global helper file
 * (`mases_core/src/Support/Helpers.php`). The helper is guaranteed
 * non-throwing: it returns `null` when no tenant is resolvable in the
 * current request context. When `mases_tenant_id()` returns null, the
 * override throws `ModelNotFoundException` — the fail-closed posture.
 * For governance routes, `tenant.membership` would have refused the
 * request with 400 or 403 in any case, so the observable outcome
 * (rejection) is unchanged; only the failure point moves earlier.
 *
 * VERIFICATION
 * -----------------------------------------------------------------------------
 * A3 does not permit test execution. The applicable test plan is
 * recorded in the A3 response's §5 (test matrix, NOT RUN). The
 * load-bearing assertion is T-04: a cross-tenant appointment id with
 * a payload that would violate the date-ordering check against the
 * cross-tenant row must return **404 `RESOURCE_NOT_FOUND`**, not 422,
 * identical to the response for a nonexistent id (T-05).
 *
 * ROLLBACK
 * -----------------------------------------------------------------------------
 * Revert this file. No schema change, no migration, no data rewrite.
 * The pre-A3 `authorize()` body (single-line `return true;`) is
 * restored by reverting the file.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
 * @version 1.1.0-a3-candidate
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Requests;

use Illuminate\Validation\Validator;
use Throwable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentInstrumentType;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentMode;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;

/**
 * UpdateGovernanceAppointmentRequest
 *
 * Validates the payload of a `PATCH` (or `PUT`) request that updates
 * an existing governance appointment.
 *
 * See the file-level change-verification block for the full set of
 * validation rules, the four shape rules that make the service's
 * immutable-FK and status-rejection invariants reachable, the
 * post-update date-ordering check, and the Amharic messages.
 *
 * See the A3 CANDIDATE block above the namespace for the RP-02
 * tenant-ownership check added to `authorize()`.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
 * @version 1.1.0-a3-candidate
 */
final class UpdateGovernanceAppointmentRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * A3 / RP-02: this method no longer returns unconditional `true`.
     * It verifies that the route-bound appointment belongs to the
     * tenant resolved for the request. When the appointment is in a
     * different tenant — or when no tenant is resolvable — the method
     * throws `ModelNotFoundException`, which the canonical renderer
     * maps to 404 `RESOURCE_NOT_FOUND`, indistinguishable from the
     * "id does not exist" case.
     *
     * See the A3 CANDIDATE block above the namespace for the full
     * rationale, and for the note that the pre-A3 §"authorize() returns
     * true unconditionally" section above is now superseded for this
     * file.
     *
     * When the model has not yet been bound (a defensive case; in
     * normal flow Laravel's `SubstituteBindings` runs before the
     * FormRequest is constructed), the method returns `true` so that
     * the controller's own tenant check handles the case.
     *
     * @return bool
     *
     * @throws ModelNotFoundException
     */
    public function authorize(): bool
    {
        $appointment = $this->route('governanceAppointment');

        if (!$appointment instanceof GovernanceAppointment) {
            // RP-02: fail closed. The canonical renderer maps this exception to 404 RESOURCE_NOT_FOUND.
            throw new ModelNotFoundException();
        }

        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;

        if ($tenantId === null || (int) $appointment->tenant_id !== (int) $tenantId) {
            throw (new ModelNotFoundException())->setModel(
                GovernanceAppointment::class,
                [$appointment->getKey()],
            );
        }

        return true;
    }

    /**
     * Prepare the data for validation.
     *
     * Trims the free-text string fields. A whitespace-only string
     * is normalised to `null`. See the file-level
     * change-verification block §"prepareForValidation() trims
     * strings".
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['instrument_reference', 'title_am', 'title_en', 'notes_am', 'notes_en'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);
                $trimmed[$field] = $value === '' ? null : $value;
            }
        }

        if ($trimmed !== []) {
            $this->merge($trimmed);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Every rule is `sometimes`. The four shape rules at the top of
     * the array are the correction that makes the service's
     * invariant checks reachable. See the file-level
     * change-verification block
     * §"WHY THE IMMUTABLE FKs AND status ARE DECLARED AS SHAPE RULES".
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // SHAPE RULES for the immutable FK columns.
            //
            // The service REJECTS any change to these three columns
            // (INV-2, INV-4, INV-6). The rejection is only reachable
            // if the key survives `validated()`. These rules make
            // the key survive; they do NOT enforce the immutability
            // — that is the service's job, because it needs to read
            // the current row to compare.
            //
            // Deliberately permissive:
            //   · `governance_body_id` — integer, min 1 (never nullable;
            //     the column is NOT NULL).
            //   · `governance_position_id` — nullable integer, min 1.
            //   · `governance_term_id` — nullable integer, min 1.
            // -----------------------------------------------------------------
            'governance_body_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],

            'governance_position_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'governance_term_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            // -----------------------------------------------------------------
            // SHAPE RULE for `status`.
            //
            // The service REJECTS any attempt to change status through
            // update() — the state machine is expressed through the
            // activate / revoke / supersede methods. The rejection is
            // only reachable if the key survives `validated()`. This
            // rule makes the key survive; it does NOT enumerate the
            // legal status values (any string passes), because the
            // service's rejection applies to ALL supplied values.
            // -----------------------------------------------------------------
            'status' => [
                'sometimes',
                'string',
            ],

            // -----------------------------------------------------------------
            // Mutable fields — standard shape rules.
            //
            // `instrument_type` and `appointment_mode` are enum-typed;
            // their values are enumerated by `Rule::enum`. The dates
            // use `date`. The free-text fields use the column widths
            // from the migration.
            // -----------------------------------------------------------------
            'instrument_type' => [
                'sometimes',
                'string',
                Rule::enum(GovernanceAppointmentInstrumentType::class),
            ],

            'instrument_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'instrument_date' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'effective_date' => [
                'sometimes',
                'date',
            ],

            'appointment_mode' => [
                'sometimes',
                'string',
                Rule::enum(GovernanceAppointmentMode::class),
            ],

            'title_am' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'title_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'notes_am' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            'notes_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * Configure the validator instance with the cross-field check.
     *
     * The check compares the POST-update effective date against the
     * POST-update instrument date. Either date may come from the
     * request or from the currently-loaded model. See the
     * file-level change-verification block §"The date ordering check
     * — withValidator()".
     *
     * Under A3 / RP-02, this method's cross-field check is unreachable
     * for cross-tenant ids — `authorize()` refuses them first. The
     * check remains for same-tenant updates, where it is the correct
     * pre-write validation.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Skip when the base rules already failed — the caller
            // receives the base failure without a confusing
            // cross-field message.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Resolve the current row from the route's model binding.
            // Returns null in the (unreachable in normal flow) case
            // where the route parameter is missing. See the file-level
            // change-verification block §"The date ordering check —
            // withValidator()".
            $appointment = $this->route('governanceAppointment');

            if (!$appointment instanceof GovernanceAppointment) {
                return;
            }

            // The post-update instrument date: request value if
            // present, otherwise the row's current value.
            $instrumentDate = $this->has('instrument_date')
                ? $this->input('instrument_date')
                : $appointment->instrument_date;

            // The post-update effective date: request value if
            // present, otherwise the row's current value.
            $effectiveDate = $this->has('effective_date')
                ? $this->input('effective_date')
                : $appointment->effective_date;

            if ($instrumentDate === null || $effectiveDate === null) {
                return;
            }

            try {
                $from = Carbon::parse($instrumentDate);
                $to   = Carbon::parse($effectiveDate);
            } catch (Throwable) {
                // Base `date` rules already validated the values that
                // were present; the model's cast guarantees a Carbon
                // for the values read off the row. This catch is
                // defensive.
                return;
            }

            if ($to->lessThan($from)) {
                $validator->errors()->add(
                    'effective_date',
                    'የጸናበት ቀን ከመሣሪያው ቀን ቀደም ማለት የለበትም።',
                );
            }
        });
    }

    /**
     * Get the inline Amharic error messages.
     *
     * Mirrors the store request's messages, with the same field
     * vocabulary and verb forms. The two files' message sets are
     * deliberately parallel — a future CR that introduces a shared
     * catalogue migrates both together.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'governance_body_id.integer'     => 'የአካል መለያ ቁጥር መሆን አለበት።',
            'governance_body_id.min'         => 'የአካል መለያ ከ 1 መብለጥ የለበትም።',

            'governance_position_id.integer' => 'የአቋም መለያ ቁጥር መሆን አለበት።',
            'governance_position_id.min'     => 'የአቋም መለያ ከ 1 መብለጥ የለበትም።',

            'governance_term_id.integer'     => 'የሥራ ዘመን መለያ ቁጥር መሆን አለበት።',
            'governance_term_id.min'         => 'የሥራ ዘመን መለያ ከ 1 መብለጥ የለበትም።',

            'status.string'                  => 'የሁኔታ እሴት ጽሑፍ መሆን አለበት።',

            'instrument_type.string'         => 'የመሣሪያ ዓይነት ጽሑፍ መሆን አለበት።',

            'instrument_reference.string'    => 'የመሣሪያ ማጣቀሻ ጽሑፍ መሆን አለበት።',
            'instrument_reference.max'       => 'የመሣሪያ ማጣቀሻ ከ 191 ቁምፊ መብለጥ የለበትም።',

            'instrument_date.date'           => 'የመሣሪያ ቀን ትክክለኛ ቀን መሆን አለበት።',

            'effective_date.date'            => 'የጸናበት ቀን ትክክለኛ ቀን መሆን አለበት።',

            'appointment_mode.string'        => 'የሹመት ዘዴ ጽሑፍ መሆን አለበት።',

            'title_am.string'                => 'ርዕስ (አማርኛ) ጽሑፍ መሆን አለበት።',
            'title_am.max'                   => 'ርዕስ (አማርኛ) ከ 150 ቁምፊ መብለጥ የለበትም።',

            'title_en.string'                => 'ርዕስ (እንግሊዝኛ) ጽሑፍ መሆን አለበት።',
            'title_en.max'                   => 'ርዕስ (እንግሊዝኛ) ከ 150 ቁምፊ መብለጥ የለበትም።',

            'notes_am.string'                => 'ማስታወሻ (አማርኛ) ጽሑፍ መሆን አለበት።',
            'notes_am.max'                   => 'ማስታወሻ (አማርኛ) ከ 500 ቁምፊ መብለጥ የለበትም።',

            'notes_en.string'                => 'ማስታወሻ (እንግሊዝኛ) ጽሑፍ መሆን አለበት።',
            'notes_en.max'                   => 'ማስታወሻ (እንግሊዝኛ) ከ 500 ቁምፊ መብለጥ የለበትም።',
        ];
    }

    /**
     * Get the inline Amharic attribute names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'governance_body_id'     => 'የአካል መለያ',
            'governance_position_id' => 'የአቋም መለያ',
            'governance_term_id'     => 'የሥራ ዘመን መለያ',
            'status'                 => 'የሁኔታ እሴት',
            'instrument_type'        => 'የመሣሪያ ዓይነት',
            'instrument_reference'   => 'የመሣሪያ ማጣቀሻ',
            'instrument_date'        => 'የመሣሪያ ቀን',
            'effective_date'         => 'የጸናበት ቀን',
            'appointment_mode'       => 'የሹመት ዘዴ',
            'title_am'               => 'ርዕስ (አማርኛ)',
            'title_en'               => 'ርዕስ (እንግሊዝኛ)',
            'notes_am'               => 'ማስታወሻ (አማርኛ)',
            'notes_en'               => 'ማስታወሻ (እንግሊዝኛ)',
        ];
    }
}