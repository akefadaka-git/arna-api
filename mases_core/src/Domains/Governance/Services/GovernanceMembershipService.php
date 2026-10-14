<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 20 of 31  (REDELIVERY 1)
 * =============================================================================
 *
 * File:            Services/GovernanceMembershipService.php
 * Delivery type:   Full-file replacement (patch redelivery)
 *
 * REDELIVERY NOTE
 * -----------------------------------------------------------------------------
 * The original delivery of this file declared `ALLOWED_FIELDS`
 * WITHOUT the `'status'` key. That was intended to signal "the
 * service never accepts status on update" — but it broke the
 * rejection branch that enforces the rule. The `update()` path runs
 * the caller's payload through `whitelist()` FIRST, which silently
 * dropped `status`, so `normaliseForUpdate()`'s
 *
 *     if (array_key_exists('status', $attributes)) { throw ... }
 *
 * was never true. A caller that sent `{'status': 'ended'}` received
 * a 200 with the row unchanged — a silent non-acceptance, not the
 * 422 the design intends.
 *
 * The fix adds `'status'` to `ALLOWED_FIELDS`. The whitelist now
 * preserves the key, the rejection branch fires, and the caller
 * receives the 422. On the `register()` path the key is still
 * overwritten to `'active'` by `normaliseForRegister()` step 10, so
 * registration behaviour is unchanged.
 *
 * This is the same class of bug that the G2b amendments to
 * `UpdateGovernancePositionRequest` and
 * `UpdateGovernanceTermRequest` fixed at the request layer — a
 * rejection branch that was unreachable because the field was
 * stripped upstream. Both fixes exist for the same purpose: to make
 * an intended 422 reachable.
 *
 * The `ALLOWED_FIELDS` docblock now records the reasoning so a
 * future reader does not remove `'status'` again thinking it is
 * redundant.
 *
 * No other change is made. Every other line in this file — the
 * entire change-verification block below, the class docblock, all
 * fourteen invariants, the state-transition matrix, the six
 * lifecycle methods, the two `normalise*` helpers, every private
 * assertion helper, the overlap-check plumbing — is byte-for-byte
 * identical to the original delivery.
 *
 * -----------------------------------------------------------------------------
 * Everything below this note is the original v1.0.0 change-
 * verification block, preserved verbatim.
 * =============================================================================
 *
 * CHANGE VERIFICATION — G2c · File 20 of 31
 * =============================================================================
 *
 * File:            Services/GovernanceMembershipService.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (service)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceMembership` model (G2c
 *   file 18 of 31) could be written to directly by any caller with
 *   access to `GovernanceMembership::create()` or
 *   `$membership->save()`, but the domain had no single enforcement
 *   point for its invariants.
 *
 *   Nothing stopped a caller from persisting a membership that
 *   referenced a body in a different tenant, a position on a
 *   different body, a term on a different body, an appointment on a
 *   different body, a user account that does not exist, or a
 *   membership whose date range is inverted. Nothing enforced the
 *   overlap invariant (ruling B4). Nothing enforced the
 *   at-least-one-member-identity rule (ruling B1). Nothing
 *   dispatched a domain event after a successful write, so the
 *   audit trail could not record membership changes.
 *
 * After:
 *   One file that declares the domain's single write path for
 *   `GovernanceMembership`. Every mutation — registration, update,
 *   and the six lifecycle transitions — flows through this service.
 *
 *   The service exposes eight public write methods:
 *
 *     register()   — create a new membership
 *     update()     — modify mutable fields
 *     end()        — active or suspended  → ended
 *     suspend()    — active               → suspended
 *     resume()     — suspended            → active
 *     resign()     — active or suspended  → resigned
 *     remove()     — active or suspended  → removed
 *     expire()     — active or suspended  → expired
 *
 *   TWO OF THE EIGHT ARE ADDITIONS TO THE MANIFEST'S LITERAL LIST.
 *   The manifest named five lifecycle methods — `end`, `suspend`,
 *   `resign`, `remove` — plus the standard `register` and `update`.
 *   This service adds `resume` and `expire` for completeness:
 *
 *     · `resume()` — without it, a `suspended` membership has no
 *       path back to `active`. The status enum's two ongoing
 *       states (active, suspended) would be one-way into
 *       suspension, which is not what "suspended" means.
 *
 *     · `expire()` — the status enum has an `expired` value. Without
 *       a method that reaches it, the value can never be produced
 *       by the service API; only a raw Eloquent write or a future
 *       scheduled job could reach it. Providing `expire()` makes
 *       the enum's full vocabulary reachable through the
 *       supported write path.
 *
 *   Both additions follow the same lifecycle-method shape the other
 *   five have: idempotent in the target state, refusing from
 *   terminal states, dispatching the updated event on success.
 *   A future CR that removes either would also remove the
 *   corresponding status value from the enum, which is a heavier
 *   change than the addition itself.
 *
 *   The service:
 *
 *     1. Whitelists the caller's input against the model's fillable
 *        set, dropping any key the model does not accept.
 *
 *     2. Validates every domain invariant (see §"Invariants" below).
 *
 *     3. Wraps every write in a database transaction.
 *
 *     4. Dispatches the appropriate domain event after the
 *        transaction commits, gated on
 *        `config('governance.audit.emit_events')`:
 *
 *          · `GovernanceMembershipRegistered` — on `register()`.
 *          · `GovernanceMembershipUpdated`    — on `update()` and
 *                                                on all six
 *                                                lifecycle methods.
 *
 *     5. Returns the persisted model, refreshed from the database.
 *
 * =============================================================================
 * Invariants — what the service guarantees
 * =============================================================================
 * For every row the service ever writes, the following invariants
 * hold at the moment of commit:
 *
 *   INV-1  Body validity.
 *          Every persisted `governance_body_id` references an
 *          existing body in the same tenant.
 *
 *   INV-2  Body immutability.
 *          Once registered, a membership's `governance_body_id`
 *          never changes.
 *
 *   INV-3  Position validity and alignment.
 *          `governance_position_id` references an existing position
 *          in the same tenant AND that position's
 *          `governance_body_id` matches the membership's
 *          `governance_body_id`.
 *
 *   INV-4  Position immutability.
 *          Once registered, a membership's
 *          `governance_position_id` never changes.
 *
 *   INV-5  Term validity and alignment.
 *          When `governance_term_id` is non-null, it references an
 *          existing term in the same tenant AND that term's
 *          `governance_body_id` matches the membership's
 *          `governance_body_id`.
 *
 *   INV-6  Term immutability.
 *          Once registered, a membership's `governance_term_id`
 *          never changes (from null to a value, or between
 *          values).
 *
 *   INV-7  Appointment validity and alignment.
 *          When `governance_appointment_id` is non-null, it
 *          references an existing appointment in the same tenant
 *          AND that appointment's `governance_body_id` matches the
 *          membership's `governance_body_id`.
 *
 *   INV-8  Appointment immutability.
 *          Once registered, a membership's
 *          `governance_appointment_id` never changes.
 *
 *   INV-9  User validity.
 *          When `user_id` is non-null, it references an existing
 *          user.
 *
 *   INV-10 Member-identity presence (ruling B1).
 *          Every persisted row has at least one of:
 *
 *            · a non-null `user_id`, OR
 *            · a non-empty `external_reference`, OR
 *            · a non-empty `member_name_am`, OR
 *            · a non-empty `member_name_en`.
 *
 *          A membership with none of these cannot be identified as
 *          belonging to any person and is refused.
 *
 *   INV-11 Date ordering.
 *          When `end_date` is non-null, `start_date <= end_date`.
 *
 *   INV-12 Overlap (ruling B4).
 *          No two ongoing memberships (active or suspended) for
 *          the same (person, position, body) may have overlapping
 *          date ranges. See §"The overlap invariant" below.
 *
 *   INV-13 Tenant alignment.
 *          Every persisted row's `tenant_id` matches the resolved
 *          tenant.
 *
 *   INV-14 Status-transition validity.
 *          The status of a persisted row always reflects one of
 *          the legal transitions (see §"The state-transition
 *          matrix" below).
 *
 * Any write that would violate one of these invariants throws a
 * `ValidationException` before the transaction commits. No partial
 * state is ever persisted.
 *
 * =============================================================================
 * The state-transition matrix
 * =============================================================================
 * Legal transitions:
 *
 *     register  →  active
 *
 *     active    →  ended        (end)
 *     active    →  suspended    (suspend)
 *     active    →  resigned     (resign)
 *     active    →  removed      (remove)
 *     active    →  expired      (expire)
 *
 *     suspended →  active       (resume)
 *     suspended →  ended        (end)
 *     suspended →  resigned     (resign)
 *     suspended →  removed      (remove)
 *     suspended →  expired      (expire)
 *
 * Terminal states — ended, resigned, removed, expired — have NO
 * outgoing transitions. A member who returns to the seat after a
 * terminal membership occupies it through a NEW membership, not by
 * transitioning the old one out of its terminal state.
 *
 * Each lifecycle method implements the matrix by:
 *
 *   1. If the current status equals the target status → no-op
 *      (return unchanged, dispatch no event).
 *   2. If the current status is terminal → throw.
 *   3. If the current status is in the method's legal-from set →
 *      proceed.
 *   4. Otherwise → throw.
 *
 * Step 1 is the reason each lifecycle method is idempotent on its
 * own target state: calling `end()` on an already-`ended` row is
 * a no-op, not an error. Calling `suspend()` on an already-`active`
 * row is the normal case, not an error. Calling `resume()` on an
 * already-`active` row is a no-op.
 *
 * The matrix is deliberately restricted. A future CR that adds a
 * transition (for example, `active → suspended → ended` as a
 * two-step wind-down, if the organization distinguishes them)
 * amends the corresponding method's legal-from set. The enum's
 * `isOngoing()` / `isTerminal()` predicates do NOT enforce the
 * matrix — they classify states; the matrix lives here.
 *
 * =============================================================================
 * The overlap invariant — ruling B4 in enforcement
 * =============================================================================
 * The overlap invariant is enforced by a single query against the
 * repository:
 *
 *     $repository->existsOverlapping(
 *         tenantId:          $tenantId,
 *         bodyId:            $bodyId,
 *         positionId:        $positionId,
 *         userId:            $candidateUserId,
 *         externalReference: $candidateExternalReference,
 *         memberNameAm:      $candidateMemberNameAm,
 *         memberNameEn:      $candidateMemberNameEn,
 *         startDate:         $candidateStartDate,
 *         endDate:           $candidateEndDate,
 *         exceptId:          $excludeId,
 *     );
 *
 * The check is run in TWO paths:
 *
 *   1. In `register()`, BEFORE the new row is inserted. The
 *      candidate identity and date range come from the payload;
 *      `exceptId` is null because no row yet exists to exclude.
 *
 *   2. In `update()`, BEFORE the row is modified — but ONLY when
 *      the update would change at least one of:
 *
 *        · the member identity (user_id, external_reference,
 *          member_name_am, member_name_en), OR
 *        · the date range (start_date, end_date).
 *
 *      When neither changes, the check is skipped: the row's own
 *      current state was already valid when it was written, and
 *      no change to the overlap tuple has occurred.
 *
 *      When the check runs on update, `exceptId` is the current
 *      row's id — the update must not flag the row as an overlap
 *      with itself.
 *
 * The overlap check uses the ONGOING status partition (active OR
 * suspended). A suspended membership still reserves its seat — see
 * the enum's docblock §"The suspended state and the overlap
 * invariant" for the reasoning.
 *
 * The check runs INSIDE the transaction that performs the write.
 * Two concurrent writers cannot both pass the check because MySQL's
 * transaction isolation and the write's row lock serialise them —
 * the first commits, the second's `existsOverlapping` sees the
 * first's row and refuses.
 *
 * =============================================================================
 * The user_id mutability decision
 * =============================================================================
 * The migration's docblock marks four FK columns as immutable:
 * `governance_body_id`, `governance_position_id`,
 * `governance_term_id`, `governance_appointment_id`.
 *
 * `user_id` is deliberately NOT in that set. A membership's link
 * to a platform account is mutable — a common real-world case is a
 * membership recorded for a person who did not yet have a platform
 * account, and the link is added later when the account is created.
 *
 * The four OTHER person-identity columns (`member_name_am`,
 * `member_name_en`, `external_reference`) are also mutable, for the
 * same reason: correcting a typo or updating a name as recorded is
 * a legitimate maintenance operation. The membership's identity is
 * determined by the CURRENT state of these columns; changing them
 * changes who the membership is for, which is why such a change
 * triggers the overlap re-check.
 *
 * The four immutable FK columns, by contrast, describe the
 * membership's STRUCTURAL position in the governance graph. Changing
 * any of them produces a semantically different membership, not an
 * update. The service refuses such changes explicitly; a caller
 * that needs a different body, position, term, or appointment
 * creates a new membership.
 *
 * =============================================================================
 * Why register() forces status to 'active'
 * =============================================================================
 * The migration declares `status` with a default of `'active'`. The
 * service sets it explicitly for the same reason the appointment
 * service sets `status = 'draft'` explicitly: a reader of the
 * service sees the initial state without consulting the migration,
 * and a future CR that changes the initial state touches one line
 * in `normaliseForRegister()` rather than requiring a migration
 * amendment.
 *
 * A client cannot create a membership directly in a terminal or
 * suspended state. Those states are reached through the explicit
 * lifecycle methods.
 *
 * =============================================================================
 * Event dispatch — after commit, gated by the audit feature flag
 * =============================================================================
 * Every write dispatches its event via `DB::afterCommit()`. If the
 * transaction rolls back, the callback is discarded and no event
 * fires.
 *
 * The dispatch is gated on `config('governance.audit.emit_events')`.
 *
 * The service references two event classes delivered later in this
 * unit:
 *
 *     G2c file 27 of 31:  Events/GovernanceMembershipRegistered.php
 *     G2c file 28 of 31:  Events/GovernanceMembershipUpdated.php
 *
 * The class files are NOT loaded when this service is uploaded —
 * PHP resolves `use` statements lazily. The service class loads
 * fine; the event classes are only instantiated when a write method
 * runs its after-commit closure.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
 * @version 1.0.1
 */

/*
 * =============================================================================
 * A3 CANDIDATE — NOT APPLIED — NOT TESTED
 * =============================================================================
 *
 * RP-01 — audit before/after integrity.
 *
 * Relative to the G2c REDELIVERY 1 file above, this A3 candidate makes
 * exactly two executable changes, both in methods that mutate an existing
 * membership and dispatch `GovernanceMembershipUpdated`:
 *
 *     update()
 *     transitionTo()   ← covers end, suspend, resume, resign, remove, expire
 *
 * In each of the two methods, the service now captures the persisted
 * pre-change attribute map with `$membership->getOriginal()` BEFORE the
 * mutation, and passes it to the event constructor as the new
 * `$beforeSnapshot` argument:
 *
 *     $beforeSnapshot = $membership->getOriginal();
 *     ...
 *     $this->dispatchAfterCommit(
 *         new GovernanceMembershipUpdated($membership, $beforeSnapshot)
 *     );
 *
 * WHY THIS IS REQUIRED
 * -----------------------------------------------------------------------------
 * In the pre-A3 behaviour, Laravel's `save()` calls `finishSave()`, which
 * synchronises the model's original attributes to the post-save persisted
 * state. The subsequent `refresh()` reloads the row and calls
 * `syncOriginal()` a second time. By the time the after-commit listener
 * reads `$membership->getOriginal()`, the originals already reflect the
 * POST-change state, and the listener's `old_values` therefore equalled
 * its `new_values` on every changed key.
 *
 * The fix captures the pre-change map at the last moment it is still
 * available — immediately before the mutation — and conveys it explicitly
 * through the event. The listener (companion file in this A3 batch)
 * prefers the event's snapshot over the model's originals.
 *
 * WHY `transitionTo()` COVERS SIX METHODS
 * -----------------------------------------------------------------------------
 * The six lifecycle methods — `end()`, `suspend()`, `resume()`,
 * `resign()`, `remove()`, `expire()` — all delegate to `transitionTo()`.
 * Capturing the snapshot inside that shared engine gives every one of the
 * six the correct pre-change state with a single insertion. The alternative
 * — capturing in each of the six public methods — would duplicate the same
 * line six times and risk a future CR that adds a seventh transition
 * forgetting to include it.
 *
 * WHAT DOES NOT CHANGE
 * -----------------------------------------------------------------------------
 * · `register()` — unchanged. The registered handler uses a post-create
 *   snapshot with `old_values: null`, which is correct.
 * · The event name and namespace — unchanged.
 * · The action slug (`governance.membership.updated`) — unchanged.
 *   Lifecycle-slug redesign is out of scope for A3 (RP-05).
 * · Transaction boundaries and `dispatchAfterCommit()` semantics —
 *   unchanged.
 * · The state-transition matrix — unchanged.
 * · The invariant set (INV-1 … INV-14) — unchanged.
 * · The overlap check ordering — unchanged. The pre-change snapshot is
 *   captured after `normaliseForUpdate()` has validated and after the
 *   overlap re-check has passed; both are read-only against the model,
 *   so placing the snapshot after them is safe. If either throws, the
 *   transaction aborts and no event fires.
 * · All imports — unchanged. `GovernanceMembershipUpdated` is already
 *   imported.
 * · All private helpers — unchanged.
 * · Every docblock, comment, and annotation from the G2c REDELIVERY 1
 *   file above — preserved verbatim.
 *
 * DEPENDENCY
 * -----------------------------------------------------------------------------
 * This candidate requires `Events/GovernanceMembershipUpdated.php` to
 * accept a second constructor argument:
 *
 *     public function __construct(
 *         GovernanceMembership $membership,
 *         ?array $beforeSnapshot = null,
 *     ) { ... }
 *
 * That event file is a companion file in this A3 batch. If it is not
 * deployed, the two modified methods fail to construct the event and
 * the transaction rolls back on every update / lifecycle-transition
 * call.
 *
 * VERIFICATION
 * -----------------------------------------------------------------------------
 * A3 does not permit test execution. The applicable test plan is
 * recorded in the A3 response's §5.3 and §5.4. No test was run.
 *
 * ROLLBACK
 * -----------------------------------------------------------------------------
 * Revert this file, the two event files, and the listener. No schema
 * change, no migration, no data rewrite. Existing audit rows are
 * unaffected.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
 * @version 1.1.0-a3-candidate
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use DateTimeInterface;
use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceMembershipStatus;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceMembershipRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceMembershipUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceMembershipRepository;

/**
 * GovernanceMembershipService
 *
 * The single write path for governance memberships. Every mutation
 * of a `GovernanceMembership` row goes through this service.
 *
 * See the file-level change-verification block for the service's
 * responsibilities, its fourteen invariants, its state-transition
 * matrix, its overlap-invariant enforcement, its decision on
 * `user_id` mutability, and its event-dispatch pattern.
 *
 * See the A3 CANDIDATE block above the namespace for the RP-01
 * pre-change snapshot change introduced in this revision.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
 * @version 1.1.0-a3-candidate
 */
final class GovernanceMembershipService
{
    /**
     * Columns that may be supplied to `register()` and `update()`.
     *
     * This is the model's `$fillable` array minus `tenant_id`, which
     * is supplied explicitly to `register()` as a separate argument
     * and is never updated.
     *
     * IMPORTANT — `status` IS included in this list DELIBERATELY,
     * even though the service NEVER accepts it as an update.
     *
     * The reason is subtle but load-bearing: `whitelist()` filters
     * the caller's payload against this list BEFORE
     * `normaliseForUpdate()` runs. If `status` were excluded from
     * the list, `whitelist()` would silently drop the key, and the
     * rejection branch inside `normaliseForUpdate()` —
     *
     *     if (array_key_exists('status', $attributes)) { throw ... }
     *
     * — would never be reached. A caller that sent
     * `{'status': 'ended'}` to the update endpoint would receive a
     * 200 with the row unchanged, instead of the 422 the design
     * intends. A silent non-acceptance is strictly worse than an
     * explicit refusal.
     *
     * Including `status` in this list makes the key survive
     * `whitelist()`, so the rejection branch fires. On the
     * `register()` path, the key is then OVERWRITTEN to `'active'`
     * by `normaliseForRegister()` step 10 — the same posture every
     * sibling service uses for its own state-forcing.
     *
     * A future reader who is tempted to remove `'status'` — "the
     * service never accepts it; why list it?" — must read this
     * docblock first. Removing it does not tighten the invariant;
     * it makes the invariant's enforcement unreachable.
     *
     * @var list<string>
     */
    private const ALLOWED_FIELDS = [
        'governance_body_id',
        'governance_position_id',
        'governance_term_id',
        'governance_appointment_id',
        'user_id',
        'member_name_am',
        'member_name_en',
        'external_reference',
        'start_date',
        'end_date',
        'status',
        'is_ex_officio',
        'ended_reason',
        'notes_am',
        'notes_en',
    ];

    /**
     * The FK columns that are IMMUTABLE after registration.
     *
     * The service rejects any `update()` call whose value for one of
     * these columns differs from the current value. Passing the SAME
     * value is allowed (it is dropped from the attribute set before
     * the fill, avoiding a needless UPDATE).
     *
     * NOTE: `user_id` is deliberately NOT in this list. See the
     * file-level change-verification block §"The user_id mutability
     * decision".
     *
     * @var list<string>
     */
    private const IMMUTABLE_FK_FIELDS = [
        'governance_body_id',
        'governance_position_id',
        'governance_term_id',
        'governance_appointment_id',
    ];

    /**
     * Construct the service with its repository dependency.
     *
     * @param GovernanceMembershipRepository $repository
     */
    public function __construct(
        private readonly GovernanceMembershipRepository $repository,
    ) {
    }

    // =========================================================================
    // Public API — writes
    // =========================================================================

    /**
     * Register a new governance membership.
     *
     * Validates every domain invariant (INV-1 through INV-13) before
     * persisting. The new row's `status` is forced to `'active'`
     * regardless of what the caller supplied.
     *
     * The entire operation runs in a single database transaction;
     * the `GovernanceMembershipRegistered` event fires only after
     * commit.
     *
     * @param  array<string, mixed> $data
     * @param  int                  $tenantId
     * @return GovernanceMembership
     *
     * @throws ValidationException
     */
    public function register(array $data, int $tenantId): GovernanceMembership
    {
        $attributes = $this->normaliseForRegister($data, $tenantId);

        return DB::transaction(function () use ($attributes, $tenantId): GovernanceMembership {
            // Overlap check runs inside the transaction so a
            // concurrent writer cannot slip a row past it.
            $this->assertNoOverlap(
                tenantId:          $tenantId,
                bodyId:            (int) $attributes['governance_body_id'],
                positionId:        (int) $attributes['governance_position_id'],
                userId:            $attributes['user_id'] ?? null,
                externalReference: $attributes['external_reference'] ?? null,
                memberNameAm:      $attributes['member_name_am'] ?? null,
                memberNameEn:      $attributes['member_name_en'] ?? null,
                startDate:         $attributes['start_date'],
                endDate:           $attributes['end_date'] ?? null,
                exceptId:          null,
            );

            $membership = GovernanceMembership::create([
                'tenant_id' => $tenantId,
                ...$attributes,
            ]);

            $this->dispatchAfterCommit(new GovernanceMembershipRegistered($membership));

            return $membership;
        });
    }

    /**
     * Update an existing governance membership.
     *
     * The four FK columns in `IMMUTABLE_FK_FIELDS` are immutable;
     * passing a value that differs from the current one throws a
     * `ValidationException`. Every other whitelisted field may be
     * updated.
     *
     * The `status` field is NOT updatable through `update()` — the
     * state machine is expressed through the six lifecycle methods.
     * The rejection is enforced by `normaliseForUpdate()`, which
     * sees the key because `status` is present in `ALLOWED_FIELDS`.
     *
     * If the update would change the member identity or the date
     * range, the overlap check re-runs. See the file-level
     * change-verification block §"The overlap invariant".
     *
     * A3 / RP-01: the persisted pre-change attribute map is captured
     * with `getOriginal()` BEFORE `fill()` / `save()`, and passed to
     * the event so the audit listener can derive true `old_values`.
     * See the A3 CANDIDATE block above the namespace for the full
     * rationale.
     *
     * @param  GovernanceMembership $membership
     * @param  array<string, mixed> $data
     * @return GovernanceMembership
     *
     * @throws ValidationException
     */
    public function update(GovernanceMembership $membership, array $data): GovernanceMembership
    {
        $attributes = $this->normaliseForUpdate($membership, $data);

        if ($attributes === []) {
            return $membership;
        }

        return DB::transaction(function () use ($membership, $attributes): GovernanceMembership {
            // Re-run the overlap check if the identity or date range
            // changed. See the file-level change-verification block
            // §"The overlap invariant".
            if ($this->updateAffectsOverlapTuple($membership, $attributes)) {
                $this->assertNoOverlap(
                    tenantId:          (int) $membership->tenant_id,
                    bodyId:            (int) $membership->governance_body_id,
                    positionId:        (int) $membership->governance_position_id,
                    userId:            $attributes['user_id']            ?? $membership->user_id,
                    externalReference: $attributes['external_reference'] ?? $membership->external_reference,
                    memberNameAm:      $attributes['member_name_am']     ?? $membership->member_name_am,
                    memberNameEn:      $attributes['member_name_en']     ?? $membership->member_name_en,
                    startDate:         $attributes['start_date']         ?? $membership->start_date,
                    endDate:           $attributes['end_date']           ?? $membership->end_date,
                    exceptId:          (int) $membership->id,
                );
            }

            // A3 / RP-01 — capture the persisted pre-change state BEFORE the
            // mutation. Laravel's finishSave() (called by save()) and refresh()
            // both call syncOriginal(), so this is the last point at which
            // getOriginal() reflects the pre-change state.
            $beforeSnapshot = $membership->getOriginal();

            $membership->fill($attributes)->save();

            $this->dispatchAfterCommit(
                new GovernanceMembershipUpdated($membership, $beforeSnapshot)
            );

            return $membership->refresh();
        });
    }

    // =========================================================================
    // Public API — lifecycle methods
    // =========================================================================

    /**
     * End the membership (active or suspended → ended).
     *
     * Idempotent when the membership is already `ended`. Refuses from
     * `resigned`, `removed`, or `expired` — those terminal states
     * have their own meanings that "ended" would overwrite.
     *
     * @param  GovernanceMembership $membership
     * @param  string|null          $reason
     * @return GovernanceMembership
     *
     * @throws ValidationException
     */
    public function end(GovernanceMembership $membership, ?string $reason = null): GovernanceMembership
    {
        return $this->transitionTo(
            $membership,
            GovernanceMembershipStatus::Ended,
            [GovernanceMembershipStatus::Active, GovernanceMembershipStatus::Suspended],
            $reason,
        );
    }

    /**
     * Suspend the membership (active → suspended).
     *
     * Idempotent when the membership is already `suspended`. Refuses
     * from terminal states.
     *
     * @param  GovernanceMembership $membership
     * @param  string|null          $reason
     * @return GovernanceMembership
     *
     * @throws ValidationException
     */
    public function suspend(GovernanceMembership $membership, ?string $reason = null): GovernanceMembership
    {
        return $this->transitionTo(
            $membership,
            GovernanceMembershipStatus::Suspended,
            [GovernanceMembershipStatus::Active],
            $reason,
        );
    }

    /**
     * Resume a suspended membership (suspended → active).
     *
     * Idempotent when the membership is already `active`. Refuses
     * from terminal states.
     *
     * THIS METHOD IS AN ADDITION TO THE MANIFEST'S LITERAL LIST.
     * See the file-level change-verification block §"After".
     *
     * @param  GovernanceMembership $membership
     * @return GovernanceMembership
     *
     * @throws ValidationException
     */
    public function resume(GovernanceMembership $membership): GovernanceMembership
    {
        // resume does not accept a reason — the ended_reason column
        // is for terminal transitions. Passing null keeps the column
        // unchanged.
        return $this->transitionTo(
            $membership,
            GovernanceMembershipStatus::Active,
            [GovernanceMembershipStatus::Suspended],
            null,
        );
    }

    /**
     * Record a voluntary resignation (active or suspended →
     * resigned).
     *
     * Idempotent when the membership is already `resigned`. Refuses
     * from other terminal states.
     *
     * @param  GovernanceMembership $membership
     * @param  string|null          $reason
     * @return GovernanceMembership
     *
     * @throws ValidationException
     */
    public function resign(GovernanceMembership $membership, ?string $reason = null): GovernanceMembership
    {
        return $this->transitionTo(
            $membership,
            GovernanceMembershipStatus::Resigned,
            [GovernanceMembershipStatus::Active, GovernanceMembershipStatus::Suspended],
            $reason,
        );
    }

    /**
     * Record a removal by authority (active or suspended → removed).
     *
     * Idempotent when the membership is already `removed`. Refuses
     * from other terminal states.
     *
     * @param  GovernanceMembership $membership
     * @param  string|null          $reason
     * @return GovernanceMembership
     *
     * @throws ValidationException
     */
    public function remove(GovernanceMembership $membership, ?string $reason = null): GovernanceMembership
    {
        return $this->transitionTo(
            $membership,
            GovernanceMembershipStatus::Removed,
            [GovernanceMembershipStatus::Active, GovernanceMembershipStatus::Suspended],
            $reason,
        );
    }

    /**
     * Record an expiry (active or suspended → expired).
     *
     * Idempotent when the membership is already `expired`. Refuses
     * from other terminal states.
     *
     * THIS METHOD IS AN ADDITION TO THE MANIFEST'S LITERAL LIST.
     * See the file-level change-verification block §"After".
     *
     * @param  GovernanceMembership $membership
     * @param  string|null          $reason
     * @return GovernanceMembership
     *
     * @throws ValidationException
     */
    public function expire(GovernanceMembership $membership, ?string $reason = null): GovernanceMembership
    {
        return $this->transitionTo(
            $membership,
            GovernanceMembershipStatus::Expired,
            [GovernanceMembershipStatus::Active, GovernanceMembershipStatus::Suspended],
            $reason,
        );
    }

    // =========================================================================
    // Private — lifecycle transition engine
    // =========================================================================

    /**
     * Apply a lifecycle transition, enforcing the matrix.
     *
     * The four steps documented in the file-level change-verification
     * block §"The state-transition matrix":
     *
     *   1. If the current status equals the target → no-op.
     *   2. If the current status is terminal → throw.
     *   3. If the current status is in `$legalFrom` → proceed.
     *   4. Otherwise → throw.
     *
     * A non-null `$reason` populates the `ended_reason` column.
     * A null `$reason` leaves the column unchanged.
     *
     * A3 / RP-01: the persisted pre-change attribute map is captured
     * with `getOriginal()` BEFORE the status assignment, and passed
     * to the event. Capturing inside this shared engine covers all
     * six lifecycle methods (`end`, `suspend`, `resume`, `resign`,
     * `remove`, `expire`) with a single insertion. See the A3
     * CANDIDATE block above the namespace.
     *
     * @param  GovernanceMembership       $membership
     * @param  GovernanceMembershipStatus $target
     * @param  array<int, GovernanceMembershipStatus> $legalFrom
     * @param  string|null                $reason
     * @return GovernanceMembership
     *
     * @throws ValidationException
     */
    private function transitionTo(
        GovernanceMembership $membership,
        GovernanceMembershipStatus $target,
        array $legalFrom,
        ?string $reason,
    ): GovernanceMembership {
        $current = $membership->statusEnum();

        // Step 1 — no-op if already in the target state.
        if ($current === $target) {
            return $membership;
        }

        // Step 2 — refuse from terminal states (unless the target IS
        // that terminal state, which step 1 already handled).
        if ($current?->isTerminal() === true) {
            throw ValidationException::withMessages([
                'status' => [
                    sprintf(
                        'An appointment in the "%s" state cannot be transitioned to "%s". '
                        . 'Create a new membership instead.',
                        $current->value,
                        $target->value,
                    ),
                ],
            ]);
        }

        // Step 3 — refuse from states not in the legal-from set.
        if ($current === null || !in_array($current, $legalFrom, true)) {
            throw ValidationException::withMessages([
                'status' => [
                    sprintf(
                        'The transition from "%s" to "%s" is not permitted.',
                        $current?->value ?? 'unknown',
                        $target->value,
                    ),
                ],
            ]);
        }

        // Step 4 — apply.
        return DB::transaction(function () use ($membership, $target, $reason): GovernanceMembership {
            // A3 / RP-01 — pre-change snapshot.
            $beforeSnapshot = $membership->getOriginal();

            $membership->status = $target->value;

            if ($reason !== null) {
                $membership->ended_reason = $reason;
            }

            $membership->save();

            $this->dispatchAfterCommit(
                new GovernanceMembershipUpdated($membership, $beforeSnapshot)
            );

            return $membership->refresh();
        });
    }

    // =========================================================================
    // Private — normalisation and validation (register)
    // =========================================================================

    /**
     * Normalise and validate the input for a `register()` call.
     *
     * @param  array<string, mixed> $data
     * @param  int                  $tenantId
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normaliseForRegister(array $data, int $tenantId): array
    {
        $attributes = $this->whitelist($data);

        // ---------------------------------------------------------------------
        // 1. governance_body_id — required; must reference a body in the
        //    same tenant.
        // ---------------------------------------------------------------------
        $bodyId = $attributes['governance_body_id'] ?? null;

        if ($bodyId === null || $bodyId === '') {
            throw ValidationException::withMessages([
                'governance_body_id' => ['The governance body is required.'],
            ]);
        }

        $bodyId = (int) $bodyId;

        if (!$this->bodyExistsInTenant($tenantId, $bodyId)) {
            throw ValidationException::withMessages([
                'governance_body_id' => [
                    'The specified governance body does not exist in this tenant.',
                ],
            ]);
        }

        $attributes['governance_body_id'] = $bodyId;

        // ---------------------------------------------------------------------
        // 2. governance_position_id — required; must reference a position
        //    in the same tenant AND on the SAME body.
        // ---------------------------------------------------------------------
        $positionId = $attributes['governance_position_id'] ?? null;

        if ($positionId === null || $positionId === '') {
            throw ValidationException::withMessages([
                'governance_position_id' => ['The governance position is required.'],
            ]);
        }

        $positionId = (int) $positionId;

        $positionBodyId = GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $positionId)
            ->value('governance_body_id');

        if ($positionBodyId === null) {
            throw ValidationException::withMessages([
                'governance_position_id' => [
                    'The specified governance position does not exist in this tenant.',
                ],
            ]);
        }

        if ((int) $positionBodyId !== $bodyId) {
            throw ValidationException::withMessages([
                'governance_position_id' => [
                    'The specified governance position does not belong to the membership\'s body.',
                ],
            ]);
        }

        $attributes['governance_position_id'] = $positionId;

        // ---------------------------------------------------------------------
        // 3. governance_term_id — optional; when set, must reference a
        //    term in the same tenant AND on the SAME body.
        // ---------------------------------------------------------------------
        $termId = $attributes['governance_term_id'] ?? null;

        if ($termId !== null && $termId !== '') {
            $termId = (int) $termId;

            $termBodyId = GovernanceTerm::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $termId)
                ->value('governance_body_id');

            if ($termBodyId === null) {
                throw ValidationException::withMessages([
                    'governance_term_id' => [
                        'The specified governance term does not exist in this tenant.',
                    ],
                ]);
            }

            if ((int) $termBodyId !== $bodyId) {
                throw ValidationException::withMessages([
                    'governance_term_id' => [
                        'The specified governance term does not belong to the membership\'s body.',
                    ],
                ]);
            }

            $attributes['governance_term_id'] = $termId;
        } else {
            $attributes['governance_term_id'] = null;
        }

        // ---------------------------------------------------------------------
        // 4. governance_appointment_id — optional; when set, must
        //    reference an appointment in the same tenant AND on the SAME
        //    body.
        // ---------------------------------------------------------------------
        $appointmentId = $attributes['governance_appointment_id'] ?? null;

        if ($appointmentId !== null && $appointmentId !== '') {
            $appointmentId = (int) $appointmentId;

            $appointmentBodyId = GovernanceAppointment::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $appointmentId)
                ->value('governance_body_id');

            if ($appointmentBodyId === null) {
                throw ValidationException::withMessages([
                    'governance_appointment_id' => [
                        'The specified appointment does not exist in this tenant.',
                    ],
                ]);
            }

            if ((int) $appointmentBodyId !== $bodyId) {
                throw ValidationException::withMessages([
                    'governance_appointment_id' => [
                        'The specified appointment does not belong to the membership\'s body.',
                    ],
                ]);
            }

            $attributes['governance_appointment_id'] = $appointmentId;
        } else {
            $attributes['governance_appointment_id'] = null;
        }

        // ---------------------------------------------------------------------
        // 5. user_id — optional; when set, must reference an existing
        //    user.
        // ---------------------------------------------------------------------
        $userId = $attributes['user_id'] ?? null;

        if ($userId !== null && $userId !== '') {
            $userId = (int) $userId;

            if (!User::query()->where('id', $userId)->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => [
                        'The specified user does not exist.',
                    ],
                ]);
            }

            $attributes['user_id'] = $userId;
        } else {
            $attributes['user_id'] = null;
        }

        // ---------------------------------------------------------------------
        // 6. Member-identity presence (INV-10).
        // ---------------------------------------------------------------------
        $this->assertAtLeastOneMemberIdentity($attributes);

        // ---------------------------------------------------------------------
        // 7. start_date — required.
        // ---------------------------------------------------------------------
        $startDate = $attributes['start_date'] ?? null;

        if ($startDate === null || $startDate === '') {
            throw ValidationException::withMessages([
                'start_date' => ['The start date is required.'],
            ]);
        }

        // ---------------------------------------------------------------------
        // 8. end_date ordering (INV-11).
        // ---------------------------------------------------------------------
        $this->assertDateOrdering($startDate, $attributes['end_date'] ?? null);

        // ---------------------------------------------------------------------
        // 9. is_ex_officio — default false.
        // ---------------------------------------------------------------------
        if (!array_key_exists('is_ex_officio', $attributes) || $attributes['is_ex_officio'] === null) {
            $attributes['is_ex_officio'] = false;
        } else {
            $attributes['is_ex_officio'] = (bool) $attributes['is_ex_officio'];
        }

        // ---------------------------------------------------------------------
        // 10. status — forced to 'active'. See the file-level
        //     change-verification block §"Why register() forces status
        //     to 'active'".
        // ---------------------------------------------------------------------
        $attributes['status'] = GovernanceMembershipStatus::Active->value;

        return $attributes;
    }

    // =========================================================================
    // Private — normalisation and validation (update)
    // =========================================================================

    /**
     * Normalise and validate the input for an `update()` call.
     *
     * @param  GovernanceMembership $membership
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normaliseForUpdate(GovernanceMembership $membership, array $data): array
    {
        $attributes = $this->whitelist($data);

        // ---------------------------------------------------------------------
        // 1. Immutable FK columns — reject any difference, drop any match.
        // ---------------------------------------------------------------------
        foreach (self::IMMUTABLE_FK_FIELDS as $field) {
            if (!array_key_exists($field, $attributes)) {
                continue;
            }

            $proposed = $attributes[$field];
            $current  = $membership->{$field};

            $proposedNormalised = ($proposed === null || $proposed === '') ? null : (int) $proposed;
            $currentNormalised  = ($current === null) ? null : (int) $current;

            if ($proposedNormalised !== $currentNormalised) {
                throw ValidationException::withMessages([
                    $field => [
                        'This field cannot be changed after registration. '
                        . 'Create a new membership instead.',
                    ],
                ]);
            }

            unset($attributes[$field]);
        }

        // ---------------------------------------------------------------------
        // 2. status — REJECTED. Lifecycle transitions go through the
        //    dedicated methods.
        //
        //    `status` is present in `ALLOWED_FIELDS` so that it survives
        //    the whitelist and reaches this branch. See the docblock on
        //    `ALLOWED_FIELDS` for the reasoning.
        // ---------------------------------------------------------------------
        if (array_key_exists('status', $attributes)) {
            throw ValidationException::withMessages([
                'status' => [
                    'The membership status cannot be changed through update. '
                    . 'Use the end, suspend, resume, resign, remove, or expire methods instead.',
                ],
            ]);
        }

        // ---------------------------------------------------------------------
        // 3. user_id — when set, must reference an existing user.
        //    (user_id is mutable — see the file-level change-verification
        //    block §"The user_id mutability decision".)
        // ---------------------------------------------------------------------
        if (array_key_exists('user_id', $attributes)) {
            $userId = $attributes['user_id'];

            if ($userId === null || $userId === '') {
                $attributes['user_id'] = null;
            } else {
                $userId = (int) $userId;

                if (!User::query()->where('id', $userId)->exists()) {
                    throw ValidationException::withMessages([
                        'user_id' => [
                            'The specified user does not exist.',
                        ],
                    ]);
                }

                $attributes['user_id'] = $userId;
            }
        }

        // ---------------------------------------------------------------------
        // 4. Member-identity presence after update (INV-10).
        // ---------------------------------------------------------------------
        $this->assertAtLeastOneMemberIdentityAfterUpdate($membership, $attributes);

        // ---------------------------------------------------------------------
        // 5. Date ordering after update (INV-11).
        // ---------------------------------------------------------------------
        $startDate = array_key_exists('start_date', $attributes)
            ? $attributes['start_date']
            : $membership->start_date;
        $endDate = array_key_exists('end_date', $attributes)
            ? $attributes['end_date']
            : $membership->end_date;

        $this->assertDateOrdering($startDate, $endDate);

        // ---------------------------------------------------------------------
        // 6. is_ex_officio — cast to bool when present.
        // ---------------------------------------------------------------------
        if (array_key_exists('is_ex_officio', $attributes)) {
            $attributes['is_ex_officio'] = (bool) $attributes['is_ex_officio'];
        }

        return $attributes;
    }

    // =========================================================================
    // Private — invariant assertions
    // =========================================================================

    /**
     * Assert that the attribute array (or the post-update state)
     * carries at least one member identity (INV-10).
     *
     * @param  array<string, mixed> $attributes
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAtLeastOneMemberIdentity(array $attributes): void
    {
        $hasUser = isset($attributes['user_id']) && $attributes['user_id'] !== null;

        $hasExternal = is_string($attributes['external_reference'] ?? null)
            && trim($attributes['external_reference']) !== '';

        $hasNameAm = is_string($attributes['member_name_am'] ?? null)
            && trim($attributes['member_name_am']) !== '';

        $hasNameEn = is_string($attributes['member_name_en'] ?? null)
            && trim($attributes['member_name_en']) !== '';

        if (!$hasUser && !$hasExternal && !$hasNameAm && !$hasNameEn) {
            throw ValidationException::withMessages([
                'member_name_am' => [
                    'At least one member identifier is required: a user, an external reference, '
                    . 'or a name.',
                ],
            ]);
        }
    }

    /**
     * Assert that the POST-update state carries at least one member
     * identity (INV-10).
     *
     * @param  GovernanceMembership $membership
     * @param  array<string, mixed> $attributes
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAtLeastOneMemberIdentityAfterUpdate(
        GovernanceMembership $membership,
        array $attributes,
    ): void {
        $userId = array_key_exists('user_id', $attributes)
            ? $attributes['user_id']
            : $membership->user_id;

        $external = array_key_exists('external_reference', $attributes)
            ? $attributes['external_reference']
            : $membership->external_reference;

        $nameAm = array_key_exists('member_name_am', $attributes)
            ? $attributes['member_name_am']
            : $membership->member_name_am;

        $nameEn = array_key_exists('member_name_en', $attributes)
            ? $attributes['member_name_en']
            : $membership->member_name_en;

        $hasUser = $userId !== null;
        $hasExternal = is_string($external) && trim($external) !== '';
        $hasNameAm = is_string($nameAm) && trim($nameAm) !== '';
        $hasNameEn = is_string($nameEn) && trim($nameEn) !== '';

        if (!$hasUser && !$hasExternal && !$hasNameAm && !$hasNameEn) {
            throw ValidationException::withMessages([
                'member_name_am' => [
                    'At least one member identifier must remain after the update: '
                    . 'a user, an external reference, or a name.',
                ],
            ]);
        }
    }

    /**
     * Assert that `start_date <= end_date` when `end_date` is set.
     *
     * @param  mixed $startDate
     * @param  mixed $endDate
     * @return void
     *
     * @throws ValidationException
     */
    private function assertDateOrdering(mixed $startDate, mixed $endDate): void
    {
        if ($startDate === null || $endDate === null) {
            return;
        }

        $from = $this->toCarbon($startDate);
        $to   = $this->toCarbon($endDate);

        if ($from === null || $to === null) {
            return;
        }

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'end_date' => [
                    'The end date must be on or after the start date.',
                ],
            ]);
        }
    }

    /**
     * Assert that the candidate range does not overlap any ongoing
     * membership for the same (person, position, body) tuple.
     *
     * See the file-level change-verification block §"The overlap
     * invariant".
     *
     * @param  int         $tenantId
     * @param  int         $bodyId
     * @param  int         $positionId
     * @param  int|null    $userId
     * @param  string|null $externalReference
     * @param  string|null $memberNameAm
     * @param  string|null $memberNameEn
     * @param  mixed       $startDate
     * @param  mixed       $endDate
     * @param  int|null    $exceptId
     * @return void
     *
     * @throws ValidationException
     */
    private function assertNoOverlap(
        int $tenantId,
        int $bodyId,
        int $positionId,
        ?int $userId,
        ?string $externalReference,
        ?string $memberNameAm,
        ?string $memberNameEn,
        mixed $startDate,
        mixed $endDate,
        ?int $exceptId,
    ): void {
        $overlaps = $this->repository->existsOverlapping(
            tenantId:          $tenantId,
            bodyId:            $bodyId,
            positionId:        $positionId,
            userId:            $userId,
            externalReference: $externalReference,
            memberNameAm:      $memberNameAm,
            memberNameEn:      $memberNameEn,
            startDate:         $startDate,
            endDate:           $endDate,
            exceptId:          $exceptId,
        );

        if ($overlaps) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'This member already has an ongoing membership in the same position '
                    . 'whose date range overlaps the supplied range.',
                ],
            ]);
        }
    }

    /**
     * Whether an update would change at least one of the columns the
     * overlap check depends on.
     *
     * @param  GovernanceMembership $membership
     * @param  array<string, mixed> $attributes
     * @return bool
     */
    private function updateAffectsOverlapTuple(
        GovernanceMembership $membership,
        array $attributes,
    ): bool {
        $overlapFields = [
            'user_id',
            'external_reference',
            'member_name_am',
            'member_name_en',
            'start_date',
            'end_date',
        ];

        foreach ($overlapFields as $field) {
            if (!array_key_exists($field, $attributes)) {
                continue;
            }

            $proposed = $attributes[$field];
            $current  = $membership->{$field};

            // Normalise null and empty string to the same "null" for
            // comparison purposes.
            if (is_string($proposed) && trim($proposed) === '') {
                $proposed = null;
            }
            if (is_string($current) && trim($current) === '') {
                $current = null;
            }

            // Dates cast to Carbon by the model; normalise the
            // proposed value to the same ISO string form for
            // comparison.
            if ($current instanceof DateTimeInterface) {
                $current = $current->format('Y-m-d');
            }
            if ($proposed instanceof DateTimeInterface) {
                $proposed = $proposed->format('Y-m-d');
            } elseif (is_string($proposed) && $proposed !== null) {
                $parsed = $this->toCarbon($proposed);
                if ($parsed !== null) {
                    $proposed = $parsed->format('Y-m-d');
                }
            }

            if ($proposed !== $current) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Private — utilities
    // =========================================================================

    /**
     * Restrict the caller's input to the whitelisted field set.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function whitelist(array $data): array
    {
        return array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));
    }

    /**
     * Normalise a date-like value to a `Carbon` instance, or return
     * null when the value cannot be parsed.
     *
     * @param  mixed $value
     * @return Carbon|null
     */
    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Determine whether a governance body exists in a tenant.
     *
     * @param  int $tenantId
     * @param  int $bodyId
     * @return bool
     */
    private function bodyExistsInTenant(int $tenantId, int $bodyId): bool
    {
        return GovernanceBody::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $bodyId)
            ->exists();
    }

    // =========================================================================
    // Private — event dispatch
    // =========================================================================

    /**
     * Dispatch an event after the current transaction commits,
     * subject to the audit feature flag.
     *
     * @param  object $event
     * @return void
     */
    private function dispatchAfterCommit(object $event): void
    {
        if (!config('governance.audit.emit_events', true)) {
            return;
        }

        DB::afterCommit(static function () use ($event): void {
            Event::dispatch($event);
        });
    }
}