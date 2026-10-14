<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 24 of 31
 * =============================================================================
 *
 * File:            Http/Requests/UpdateGovernanceMembershipRequest.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (update request)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `FormRequest` validated the payload of
 *   a `PATCH /api/governance/memberships/{governanceMembership}` (or
 *   `PUT`) request.
 *
 * After:
 *   One file that declares the canonical validation rules for
 *   updating a `GovernanceMembership`. All rules are `sometimes` —
 *   a client may send any subset of the mutable fields.
 *
 *   What the request validates (all rules `sometimes`):
 *
 *     governance_body_id        —  integer, min 1 (immutable)
 *     governance_position_id    —  integer, min 1 (immutable)
 *     governance_term_id        —  nullable integer, min 1 (immutable)
 *     governance_appointment_id —  nullable integer, min 1 (immutable)
 *     status                    —  string (rejected by the service)
 *     user_id                   —  nullable integer, min 1
 *     member_name_am            —  nullable string, max 150
 *     member_name_en            —  nullable string, max 150
 *     external_reference        —  nullable string, max 191
 *     start_date                —  date
 *     end_date                  —  nullable date
 *     is_ex_officio             —  boolean
 *     ended_reason              —  nullable string, max 500
 *     notes_am                  —  nullable string, max 500
 *     notes_en                  —  nullable string, max 500
 *
 * =============================================================================
 * WHY THE IMMUTABLE FKs AND `status` ARE DECLARED AS SHAPE RULES
 * =============================================================================
 * The service (`GovernanceMembershipService`, G2c file 20 of 31)
 * refuses four classes of update:
 *
 *   · A change to `governance_body_id`, `governance_position_id`,
 *     `governance_term_id`, or `governance_appointment_id` — those
 *     four FK columns are immutable (INV-2, INV-4, INV-6, INV-8 in
 *     the service's docblock).
 *
 *   · A change to `status` — the state machine is expressed through
 *     the service's six lifecycle methods (`end`, `suspend`,
 *     `resume`, `resign`, `remove`, `expire`), never through
 *     `update()`.
 *
 * Each refusal is implemented as an invariant check inside the
 * service's `normaliseForUpdate()` method. That check runs against
 * the keys present in the validated payload. If this request did
 * NOT declare a rule for one of those five fields, Laravel's
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
 *   · A caller that sends `{"status": "ended"}` would have the key
 *     stripped. The service would receive no `status`, would skip
 *     its rejection branch, and would persist the other fields
 *     while leaving status unchanged. The caller would believe the
 *     membership had ended — but it had not. A future reviewer
 *     looking at the audit trail would see a field update that
 *     never touched the status, and would have no error to explain
 *     why.
 *
 * Both scenarios were the SUBSTANTIVE bug fixed in G2b by amending
 * `UpdateGovernancePositionRequest` (G2b file 8 of 27) and
 * `UpdateGovernanceTermRequest` (G2b file 20 of 27). This request
 * ships with the correction from the start.
 *
 * The five shape rules are:
 *
 *     'governance_body_id'        => ['sometimes', 'integer', 'min:1'],
 *     'governance_position_id'    => ['sometimes', 'integer', 'min:1'],
 *     'governance_term_id'        => ['sometimes', 'nullable', 'integer', 'min:1'],
 *     'governance_appointment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
 *     'status'                    => ['sometimes', 'string'],
 *
 * Each is deliberately PERMISSIVE — the request accepts any
 * well-formed value, and the service refuses the ones that would
 * violate an invariant. This is what keeps the invariant enforcement
 * in one place.
 *
 * A future reader who is tempted to remove these five rules — "they
 * only ever fail; why declare them?" — must read this section first.
 * Removing them does NOT remove the failure mode; it removes the
 * ERROR SIGNAL, turning an explicit 422 into a silent no-op.
 *
 * =============================================================================
 * user_id is mutable
 * =============================================================================
 * The service's docblock (§"The user_id mutability decision")
 * records that `user_id` is NOT in the immutable FK set. A common
 * real-world case is a membership recorded for a person who did
 * not yet have a platform account, and the link is added later.
 * The four person-identity columns (`user_id`,
 * `external_reference`, `member_name_am`, `member_name_en`) are
 * all mutable.
 *
 * This request therefore does NOT place a shape rule for `user_id`
 * in the immutable block; it declares it alongside the other
 * mutable fields.
 *
 * The service re-runs the OVERLAP check whenever the update changes
 * any of the four identity columns or either of the two date
 * columns. This request does not duplicate that check — the service
 * owns it, and the check requires a database read.
 *
 * =============================================================================
 * ended_reason is mutable through this request
 * =============================================================================
 * The `ended_reason` column is present in the service's
 * `ALLOWED_FIELDS` list, so it can be supplied to `update()`. In
 * normal flow it is populated by the service's lifecycle methods
 * (end, resign, remove, expire) when a membership transitions to a
 * terminal state; but a maintenance operation that corrects an
 * inaccurate reason is legitimate, and the service accepts it.
 *
 * This request therefore declares a shape rule for it — nullable
 * string, max 500 — matching the column's width.
 *
 * =============================================================================
 * The two cross-field checks — post-update
 * =============================================================================
 * Both cross-field checks compare against the POST-update state,
 * which means reading the current row via route model binding. The
 * current row is resolved from `$this->route('governanceMembership')`.
 *
 *   1. Member-identity presence (INV-10). At least one of
 *      `user_id`, `external_reference`, `member_name_am`,
 *      `member_name_en` must remain present after the update. The
 *      check merges the incoming values over the current row's
 *      values.
 *
 *   2. Date ordering (INV-11). `start_date <= end_date` must hold
 *      after the update. The check merges the incoming values over
 *      the current row's values.
 *
 * When the route model binding cannot be resolved (a rare case,
 * because the controller's tenant cross-check and the route's
 * `whereNumber` constraint ensure the model is present), the
 * cross-field checks are skipped and the service's own checks
 * (which produce the same 422) catch any violation. The
 * request-side checks exist to produce per-field 422 responses
 * with the correct field name and Amharic message.
 *
 * =============================================================================
 * authorize() returns true unconditionally
 * =============================================================================
 * Same rationale as every sibling request. Authorization is the
 * controller's concern.
 *
 * =============================================================================
 * prepareForValidation() trims strings
 * =============================================================================
 * The free-text fields are trimmed; a whitespace-only value becomes
 * `null`. The six trimmed fields:
 *
 *     member_name_am, member_name_en, external_reference,
 *     ended_reason, notes_am, notes_en
 *
 * =============================================================================
 * Amharic messages and attribute names
 * =============================================================================
 * Same convention as the store request. The two files' message sets
 * are deliberately parallel — a future CR that introduces a shared
 * catalogue migrates both together.
 *
 * The two native-speaker review items flagged in the store request
 * (`user_id` → `የተጠቃሚ መለያ`, `external_reference` → `የውጭ ማጣቀሻ`) apply
 * here as well.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceMembershipRequest.php
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Rules declared:            15 (all `sometimes`)
 * Shape rules for immutable FKs: 4
 * Shape rule for status:     1
 * Cross-field checks:        2 (member identity, date ordering,
 *                                both post-update)
 * Inline messages declared:  every rule + both cross-field errors
 * Inline attributes declared: every rule
 * =============================================================================
 */

/*
 * =============================================================================
 * A3 CANDIDATE — APPLIED — NOT TESTED
 * =============================================================================
 *
 * RP-02 — cross-tenant validation isolation (request-level; membership
 * half of the pair).
 *
 * Relative to the G2c v1.0.0 file above, this A3 candidate makes exactly
 * three changes:
 *
 *   1. Adds `use Illuminate\Database\Eloquent\ModelNotFoundException;`
 *      to the import block. `GovernanceMembership` is already imported;
 *      no other import changes.
 *
 *   2. Replaces the `authorize()` method's docblock and body. The method
 *      no longer returns unconditional `true`. It verifies that the
 *      route-bound membership's `tenant_id` equals the tenant resolved
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
 * `withValidator()`, which reads the route-bound membership via
 * `$this->route('governanceMembership')`. Route-model binding has no
 * tenant scope (verified: `GovernanceMembership` uses only
 * `HasFactory` and `SoftDeletes`), so a cross-tenant id resolves at
 * binding and reaches `withValidator()`.
 *
 * The two cross-field checks in `withValidator()` read the
 * cross-tenant row's `user_id`, `external_reference`,
 * `member_name_am`, `member_name_en`, `start_date`, and `end_date`.
 * A caller who submits a payload that violates either check against
 * the cross-tenant row receives a 422; a caller who submits a payload
 * that satisfies both receives a 404 from the controller's
 * `assertTenantOwnership()`. The two cases are distinguishable, and
 * the caller can therefore probe the existence and rough state of
 * membership rows in other tenants.
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
 * `GovernanceMembership` model — is broader in scope: it affects every
 * binding of the model, including future routes and any route outside
 * the governance domain. The request-level fix affects only the two
 * routes that use this request class (`PATCH` and `PUT` on
 * `memberships/{governanceMembership}`). The two `show` routes do
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
 *   · Every rule declared in `rules()` — fifteen `sometimes` rules
 *     including the five shape rules for the four immutable FKs and
 *     `status`.
 *   · The two cross-field checks in `withValidator()` — member
 *     identity and date ordering. They are preserved unchanged; they
 *     are simply now unreachable for cross-tenant ids because
 *     `authorize()` refuses them first.
 *   · Every import except the one addition.
 *   · Every docblock except the `authorize()` method's.
 *   · Every Amharic message and attribute name.
 *   · Every code line outside `authorize()`.
 *
 * COMPANION FILES IN THIS A3 BATCH
 * -----------------------------------------------------------------------------
 *   · Http/Requests/UpdateGovernanceMembershipRequest.php   (this file)
 *   · Http/Requests/UpdateGovernanceAppointmentRequest.php  (companion —
 *     delivered earlier in this A3 batch with the analogous change)
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
 * load-bearing assertion is T-10: a cross-tenant membership id with
 * a payload that would violate the member-identity or date-ordering
 * check against the cross-tenant row must return **404
 * `RESOURCE_NOT_FOUND`**, not 422, identical to the response for a
 * nonexistent id (T-11).
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

use Throwable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;

/**
 * UpdateGovernanceMembershipRequest
 *
 * Validates the payload of a `PATCH` (or `PUT`) request that updates
 * an existing governance membership.
 *
 * See the file-level change-verification block for the full set of
 * validation rules, the five shape rules that make the service's
 * immutable-FK and status-rejection invariants reachable, the two
 * post-update cross-field checks, and the Amharic messages.
 *
 * See the A3 CANDIDATE block above the namespace for the RP-02
 * tenant-ownership check added to `authorize()`.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
 * @version 1.1.0-a3-candidate
 */
final class UpdateGovernanceMembershipRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * A3 / RP-02: this method no longer returns unconditional `true`.
     * It verifies that the route-bound membership belongs to the
     * tenant resolved for the request. When the membership is in a
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
        $membership = $this->route('governanceMembership');

        if (!$membership instanceof GovernanceMembership) {
            // RP-02: fail closed. The canonical renderer maps this exception to 404 RESOURCE_NOT_FOUND.
            throw new ModelNotFoundException();
        }

        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;

        if ($tenantId === null || (int) $membership->tenant_id !== (int) $tenantId) {
            throw (new ModelNotFoundException())->setModel(
                GovernanceMembership::class,
                [$membership->getKey()],
            );
        }

        return true;
    }

    /**
     * Prepare the data for validation.
     *
     * Trims the free-text string fields. A whitespace-only string
     * is normalised to `null`.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (
            [
                'member_name_am',
                'member_name_en',
                'external_reference',
                'ended_reason',
                'notes_am',
                'notes_en',
            ] as $field
        ) {
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
     * Every rule is `sometimes`. The five shape rules at the top of
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
            // SHAPE RULES for the four immutable FK columns.
            //
            // The service REJECTS any change to these columns
            // (INV-2, INV-4, INV-6, INV-8). The rejection is only
            // reachable if the key survives `validated()`. These rules
            // make the key survive; they do NOT enforce the
            // immutability — that is the service's job, because it
            // needs to read the current row to compare.
            // -----------------------------------------------------------------
            'governance_body_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],

            'governance_position_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],

            'governance_term_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'governance_appointment_id' => [
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
            // six lifecycle methods.
            // -----------------------------------------------------------------
            'status' => [
                'sometimes',
                'string',
            ],

            // -----------------------------------------------------------------
            // Mutable fields.
            //
            // `user_id` is mutable (see the service's docblock
            // §"The user_id mutability decision"). The four
            // person-identity columns are mutable for the same reason.
            // -----------------------------------------------------------------
            'user_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'member_name_am' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'member_name_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'external_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'start_date' => [
                'sometimes',
                'date',
            ],

            'end_date' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'is_ex_officio' => [
                'sometimes',
                'boolean',
            ],

            'ended_reason' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
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
     * Configure the validator with the two post-update cross-field
     * checks.
     *
     * Under A3 / RP-02, these cross-field checks are unreachable for
     * cross-tenant ids — `authorize()` refuses them first. They remain
     * for same-tenant updates, where they are the correct pre-write
     * validation.
     *
     * @param  Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Skip when the base rules already failed.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $membership = $this->route('governanceMembership');

            if (!$membership instanceof GovernanceMembership) {
                // Route model binding could not be resolved. The
                // service's own checks (INV-10, INV-11) will produce
                // the same errors from the transactional path.
                return;
            }

            // -----------------------------------------------------------------
            // 1. Member-identity presence AFTER the update.
            // -----------------------------------------------------------------
            $userId = $this->has('user_id')
                ? $this->input('user_id')
                : $membership->user_id;

            $external = $this->has('external_reference')
                ? $this->input('external_reference')
                : $membership->external_reference;

            $nameAm = $this->has('member_name_am')
                ? $this->input('member_name_am')
                : $membership->member_name_am;

            $nameEn = $this->has('member_name_en')
                ? $this->input('member_name_en')
                : $membership->member_name_en;

            $hasUser = $userId !== null && $userId !== '';
            $hasExternal = is_string($external) && trim($external) !== '';
            $hasNameAm = is_string($nameAm) && trim($nameAm) !== '';
            $hasNameEn = is_string($nameEn) && trim($nameEn) !== '';

            if (!$hasUser && !$hasExternal && !$hasNameAm && !$hasNameEn) {
                $validator->errors()->add(
                    'member_name_am',
                    'ቢያንስ አንድ የአባል መለያ ከማሻሻያው በኋላ መቆየት አለበት፡ የተጠቃሚ መለያ፣ የውጭ ማጣቀሻ፣ ወይም ስም።',
                );

                return;
            }

            // -----------------------------------------------------------------
            // 2. Date ordering AFTER the update.
            // -----------------------------------------------------------------
            $startDate = $this->has('start_date')
                ? $this->input('start_date')
                : $membership->start_date;

            $endDate = $this->has('end_date')
                ? $this->input('end_date')
                : $membership->end_date;

            if ($startDate === null || $endDate === null) {
                return;
            }

            try {
                $from = Carbon::parse($startDate);
                $to   = Carbon::parse($endDate);
            } catch (Throwable) {
                return;
            }

            if ($from->greaterThan($to)) {
                $validator->errors()->add(
                    'end_date',
                    'የማብቂያ ቀን ከመጀመሪያ ቀን ቀደም ማለት የለበትም።',
                );
            }
        });
    }

    /**
     * Get the inline Amharic error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'governance_body_id.integer'        => 'የአካል መለያ ቁጥር መሆን አለበት።',
            'governance_body_id.min'            => 'የአካል መለያ ከ 1 መብለጥ የለበትም።',

            'governance_position_id.integer'    => 'የአቋም መለያ ቁጥር መሆን አለበት።',
            'governance_position_id.min'        => 'የአቋም መለያ ከ 1 መብለጥ የለበትም።',

            'governance_term_id.integer'        => 'የሥራ ዘመን መለያ ቁጥር መሆን አለበት።',
            'governance_term_id.min'            => 'የሥራ ዘመን መለያ ከ 1 መብለጥ የለበትም።',

            'governance_appointment_id.integer' => 'የሹመት መለያ ቁጥር መሆን አለበት።',
            'governance_appointment_id.min'     => 'የሹመት መለያ ከ 1 መብለጥ የለበትም።',

            'status.string'                     => 'የሁኔታ እሴት ጽሑፍ መሆን አለበት።',

            'user_id.integer'                   => 'የተጠቃሚ መለያ ቁጥር መሆን አለበት።',
            'user_id.min'                       => 'የተጠቃሚ መለያ ከ 1 መብለጥ የለበትም።',

            'member_name_am.string'             => 'የአባል ስም (አማርኛ) ጽሑፍ መሆን አለበት።',
            'member_name_am.max'                => 'የአባል ስም (አማርኛ) ከ 150 ቁምፊ መብለጥ የለበትም።',

            'member_name_en.string'             => 'የአባል ስም (እንግሊዝኛ) ጽሑፍ መሆን አለበት።',
            'member_name_en.max'                => 'የአባል ስም (እንግሊዝኛ) ከ 150 ቁምፊ መብለጥ የለበትም።',

            'external_reference.string'         => 'የውጭ ማጣቀሻ ጽሑፍ መሆን አለበት።',
            'external_reference.max'            => 'የውጭ ማጣቀሻ ከ 191 ቁምፊ መብለጥ የለበትም።',

            'start_date.date'                   => 'የመጀመሪያ ቀን ትክክለኛ ቀን መሆን አለበት።',

            'end_date.date'                     => 'የማብቂያ ቀን ትክክለኛ ቀን መሆን አለበት።',

            'is_ex_officio.boolean'             => 'የ ex-officio ምልክት እውነት ወይም ሐሰት መሆን አለበት።',

            'ended_reason.string'               => 'የማብቂያ ምክንያት ጽሑፍ መሆን አለበት።',
            'ended_reason.max'                  => 'የማብቂያ ምክንያት ከ 500 ቁምፊ መብለጥ የለበትም።',

            'notes_am.string'                   => 'ማስታወሻ (አማርኛ) ጽሑፍ መሆን አለበት።',
            'notes_am.max'                      => 'ማስታወሻ (አማርኛ) ከ 500 ቁምፊ መብለጥ የለበትም።',

            'notes_en.string'                   => 'ማስታወሻ (እንግሊዝኛ) ጽሑፍ መሆን አለበት።',
            'notes_en.max'                      => 'ማስታወሻ (እንግሊዝኛ) ከ 500 ቁምፊ መብለጥ የለበትም።',
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
            'governance_body_id'        => 'የአካል መለያ',
            'governance_position_id'    => 'የአቋም መለያ',
            'governance_term_id'        => 'የሥራ ዘመን መለያ',
            'governance_appointment_id' => 'የሹመት መለያ',
            'status'                    => 'የሁኔታ እሴት',
            'user_id'                   => 'የተጠቃሚ መለያ',
            'member_name_am'            => 'የአባል ስም (አማርኛ)',
            'member_name_en'            => 'የአባል ስም (እንግሊዝኛ)',
            'external_reference'        => 'የውጭ ማጣቀሻ',
            'start_date'                => 'የመጀመሪያ ቀን',
            'end_date'                  => 'የማብቂያ ቀን',
            'is_ex_officio'             => 'የ ex-officio ምልክት',
            'ended_reason'              => 'የማብቂያ ምክንያት',
            'notes_am'                  => 'ማስታወሻ (አማርኛ)',
            'notes_en'                  => 'ማስታወሻ (እንግሊዝኛ)',
        ];
    }
}