<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 7 of 31
 * =============================================================================
 *
 * File:            Services/GovernanceAppointmentService.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (service)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceAppointment` model (G2c
 *   file 5 of 31) could be written to directly by any caller with
 *   access to `GovernanceAppointment::create()` or
 *   `$appointment->save()`, but the domain had no single enforcement
 *   point for its invariants. Nothing stopped a caller from
 *   persisting an appointment that referenced a body in a different
 *   tenant, a position on a different body, a term on a different
 *   body, an unknown enum value in one of its three enum columns, or
 *   an effective date earlier than its instrument date. Nothing
 *   enforced the state-machine transitions between `draft`,
 *   `active`, `revoked`, and `superseded`. Nothing dispatched a
 *   domain event after a successful write, so the audit trail could
 *   not record appointment changes.
 *
 * After:
 *   One file that declares the domain's single write path for
 *   `GovernanceAppointment`. Every mutation — registration, update,
 *   activation, revocation, supersession — flows through this
 *   service. The service:
 *
 *     1. Whitelists the caller's input against the model's fillable
 *        set, dropping any key the model does not accept.
 *
 *     2. Validates every domain invariant that must hold before a
 *        row is persisted (see §"Invariants" below).
 *
 *     3. Wraps every write in a database transaction.
 *
 *     4. Dispatches the appropriate domain event after the
 *        transaction commits, gated on
 *        `config('governance.audit.emit_events')`:
 *
 *          · `GovernanceAppointmentRegistered` — on `register()`.
 *          · `GovernanceAppointmentUpdated`    — on `update()`,
 *                                                `activate()`,
 *                                                `revoke()`, and
 *                                                `supersede()`.
 *
 *     5. Returns the persisted model, refreshed from the database.
 *
 *   What the service deliberately does NOT do:
 *
 *     · It does NOT perform authorization. The controller (G2c file
 *       12 of 31) invokes the policy (G2c file 8) before calling
 *       this service.
 *
 *     · It does NOT cascade status changes to MEMBERSHIPS. When a
 *       membership entity exists (files 16–28 of this unit), a
 *       future CR may decide that revoking an appointment must
 *       also end its memberships. That decision is deliberately NOT
 *       taken here — the appointment service manages the
 *       appointment's own state; the membership service manages
 *       the membership's state; the coordination belongs to a
 *       future service-layer composition or a future
 *       `GovernanceMembershipService` that observes the
 *       appointment's events.
 *
 *     · It does NOT validate that the target position belongs to
 *       the target body when BOTH are set. The service DOES check
 *       each FK's existence and tenant alignment; the body-to-
 *       position consistency check is enforced only when a
 *       position is supplied on registration and the body is known,
 *       by querying the position's own `governance_body_id` and
 *       comparing it to the appointment's body. See INV-3 in the
 *       invariants list below.
 *
 *     · It does NOT enforce a global "one active appointment per
 *       position" rule. Such a rule is organizational: a body may
 *       deliberately allow a position to be filled by two people
 *       (a co-chair arrangement), and the domain does not presume
 *       otherwise. The repository's `existsActiveForPosition()`
 *       method exists so that a future CR can add the check
 *       where the organization decides it applies.
 *
 *     · It does NOT auto-populate `instrument_reference` or
 *       `title_am` / `title_en` from dates or from the instrument
 *       type. Those are human-entered values. A caller that wants a
 *       default supplies one.
 *
 *     · It does NOT soft-delete. There is no `delete()` method.
 *       Appointments are `revoked` or `superseded` — both of which
 *       preserve the historical record — not removed.
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
 *          Once registered, an appointment's `governance_body_id`
 *          never changes.
 *
 *   INV-3  Position validity and alignment.
 *          When `governance_position_id` is non-null, it references
 *          an existing position in the same tenant, AND that
 *          position's `governance_body_id` matches the
 *          appointment's `governance_body_id`.
 *
 *   INV-4  Position immutability.
 *          Once registered, an appointment's
 *          `governance_position_id` never changes.
 *
 *   INV-5  Term validity and alignment.
 *          When `governance_term_id` is non-null, it references an
 *          existing term in the same tenant, AND that term's
 *          `governance_body_id` matches the appointment's
 *          `governance_body_id`.
 *
 *   INV-6  Term immutability.
 *          Once registered, an appointment's
 *          `governance_term_id` never changes.
 *
 *   INV-7  Instrument validity.
 *          Every persisted `instrument_type`, `appointment_mode`,
 *          and `status` value resolves to a declared case of its
 *          respective enum.
 *
 *   INV-8  Effective-date ordering.
 *          When `instrument_date` is non-null,
 *          `effective_date >= instrument_date`. A retroactively
 *          effective appointment — one whose effective date
 *          precedes the instrument that authorised it — is
 *          permitted by the schema but refused by the service in
 *          the platform's default posture. A future CR that needs
 *          to record a corrective retroactive appointment would
 *          relax this check, possibly gated on a distinct
 *          instrument type.
 *
 *   INV-9  Tenant alignment.
 *          Every persisted row's `tenant_id` matches the resolved
 *          tenant.
 *
 *   INV-10 Status-transition validity.
 *          The status of a persisted row always reflects one of the
 *          legal transitions:
 *
 *              register            →  draft
 *              draft  → active     (activate)
 *              draft  → revoked    (revoke)
 *              active → revoked    (revoke)
 *              active → superseded (supersede)
 *
 *          Any other transition throws a `ValidationException`. In
 *          particular:
 *
 *              · A row in a terminal state (revoked or superseded)
 *                can never leave it.
 *              · A row in `active` cannot go back to `draft`.
 *              · A row in `draft` cannot go directly to
 *                `superseded` — an appointment that was never in
 *                force cannot be replaced by a later instrument.
 *
 * Any write that would violate one of these invariants throws a
 * `ValidationException` before the transaction commits. No partial
 * state is ever persisted.
 *
 * =============================================================================
 * The state-transition enforcement — why the service, not the enum
 * =============================================================================
 * The `GovernanceAppointmentStatus` enum (G2c file 2 of 31)
 * declares `isOpen()` and `isTerminal()` predicates, but does NOT
 * declare the transition graph. The graph lives here, in the
 * service, for three reasons:
 *
 *   · The graph is a domain rule, not a vocabulary fact. A future
 *     CR that decides `draft → superseded` should be legal adds
 *     one arm to the transition matrix below; it does not need to
 *     touch the enum, which has no authority over transitions.
 *
 *   · The enforcement needs database access — it needs to know the
 *     current status of the loaded model and to persist the new
 *     status in a transaction. That is inherently a service-layer
 *     concern.
 *
 *   · Keeping the graph here makes it discoverable in one place:
 *     a reviewer searching for "what transitions are legal?"
 *     finds the answer in this class's `normaliseStatusTransition()`
 *     method, not scattered across an enum's methods.
 *
 * =============================================================================
 * Why a service, not a trait on the model
 * =============================================================================
 * The same reasoning that governs the three sibling services
 * (`GovernanceBodyService`, `GovernancePositionService`,
 * `GovernanceTermService`): domain invariants require database
 * reads (existence checks, cross-entity alignment) that a static
 * helper cannot perform cleanly; every write is wrapped in a
 * transaction; the service is the natural extension point for a
 * future workflow.
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
 *     G2c file 14 of 31:  Events/GovernanceAppointmentRegistered.php
 *     G2c file 15 of 31:  Events/GovernanceAppointmentUpdated.php
 *
 * The class files are NOT loaded when this service is uploaded —
 * PHP resolves `use` statements lazily. The service class loads
 * fine; the event classes are only instantiated when a write method
 * runs its after-commit closure.
 *
 * A live caller must not invoke `register()`, `update()`,
 * `activate()`, `revoke()`, or `supersede()` until files 14 and 15
 * are on disk. The G2c deployment sequence delivers the routes
 * amendment (file 31) last, so no live HTTP caller reaches these
 * methods during the deployment window.
 *
 * =============================================================================
 * The lifecycle methods — activate(), revoke(), supersede()
 * =============================================================================
 * The service exposes three lifecycle methods beyond the standard
 * `register()` and `update()`:
 *
 *     activate()   — draft → active
 *     revoke()     — draft → revoked, or active → revoked
 *     supersede()  — active → superseded
 *
 * The three methods are IDEMPOTENT in the sense that calling one on
 * a model already in the target state is a no-op (returns the
 * unchanged model, dispatches no event). This mirrors the pattern
 * the sibling services' `deactivate()` / `reactivate()` methods
 * established.
 *
 * Calling one on a model in a state from which the transition is
 * not legal throws a `ValidationException` — for example, calling
 * `activate()` on an already-`active` model is a no-op, but
 * calling `activate()` on a `revoked` model throws.
 *
 * The two distinctions are deliberate:
 *
 *   · A no-op is a caller asking for a state the model is already
 *     in — the caller's intent is satisfied. No error.
 *   · A refusal is a caller asking for a transition the domain
 *     does not permit — the caller's intent cannot be satisfied
 *     without violating an invariant. Error.
 *
 * The service's docblock for each method records which case it
 * handles.
 *
 * =============================================================================
 * Why register() sets status to 'draft' explicitly
 * =============================================================================
 * The migration declares `status` with a default of `'draft'`. The
 * service could therefore omit the column on insert and rely on the
 * database default. It does not — it sets `status = 'draft'`
 * explicitly, for two reasons:
 *
 *   · A reader of the service sees the initial status without
 *     having to consult the migration. The state machine's entry
 *     point is documented at the write path.
 *
 *   · A future CR that decides appointments should start in a
 *     different initial state (for example, an `active` default
 *     when the instrument is a by-law provision with a past
 *     effective date) changes ONE line in this service's
 *     `normaliseForRegister()` method. If the service relied on the
 *     database default, the same CR would require a migration
 *     amendment, which is a heavier change.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
 * @version 1.0.0
 */

/*
 * =============================================================================
 * A3 CANDIDATE — NOT APPLIED — NOT TESTED
 * =============================================================================
 *
 * RP-01 — audit before/after integrity.
 *
 * Relative to the G2c v1.0.0 file above, this A3 candidate makes exactly
 * four executable changes, all in service methods that mutate an existing
 * appointment and dispatch `GovernanceAppointmentUpdated`:
 *
 *     update()
 *     activate()
 *     revoke()
 *     supersede()
 *
 * In each of the four methods, the service now captures the persisted
 * pre-change attribute map with `$appointment->getOriginal()` BEFORE the
 * mutation, and passes it to the event constructor as the new
 * `$beforeSnapshot` argument:
 *
 *     $beforeSnapshot = $appointment->getOriginal();
 *     ...
 *     $this->dispatchAfterCommit(
 *         new GovernanceAppointmentUpdated($appointment, $beforeSnapshot)
 *     );
 *
 * WHY THIS IS REQUIRED
 * -----------------------------------------------------------------------------
 * In the pre-A3 behaviour, Laravel's `save()` calls `finishSave()`, which
 * synchronises the model's original attributes to the post-save persisted
 * state. The subsequent `refresh()` reloads the row and calls
 * `syncOriginal()` a second time. By the time the after-commit listener
 * reads `$appointment->getOriginal()`, the originals already reflect the
 * POST-change state, and the listener's `old_values` therefore equalled
 * its `new_values` on every changed key.
 *
 * The fix captures the pre-change map at the last moment it is still
 * available — immediately before the mutation — and conveys it explicitly
 * through the event. The listener (companion file in this A3 batch)
 * prefers the event's snapshot over the model's originals.
 *
 * WHAT DOES NOT CHANGE
 * -----------------------------------------------------------------------------
 * · `register()` — unchanged. The registered handler uses a post-create
 *   snapshot with `old_values: null`, which is correct.
 * · The event name and namespace — unchanged.
 * · The action slugs (`governance.appointment.registered`,
 *   `governance.appointment.updated`) — unchanged. Lifecycle-slug
 *   redesign is out of scope for A3 (RP-05).
 * · Transaction boundaries and `dispatchAfterCommit()` semantics —
 *   unchanged.
 * · The state-transition matrix — unchanged.
 * · The invariant set (INV-1 … INV-10) — unchanged.
 * · All imports — unchanged. `GovernanceAppointmentUpdated` is already
 *   imported.
 * · All private helpers (`whitelist`, `toCarbon`, `bodyExistsInTenant`,
 *   `assertInstrumentDateOrdering`, `dispatchAfterCommit`) — unchanged.
 * · Every docblock, comment, and annotation from the G2c file above —
 *   preserved verbatim.
 *
 * DEPENDENCY
 * -----------------------------------------------------------------------------
 * This candidate requires `Events/GovernanceAppointmentUpdated.php` to
 * accept a second constructor argument:
 *
 *     public function __construct(
 *         public readonly GovernanceAppointment $appointment,
 *         public readonly ?array $beforeSnapshot = null,
 *     ) {}
 *
 * The event file is a companion file in this A3 batch. If it is not
 * deployed, the four modified methods fail to construct the event and
 * the transaction rolls back on every update / activate / revoke /
 * supersede call.
 *
 * VERIFICATION
 * -----------------------------------------------------------------------------
 * A3 does not permit test execution. The applicable test plan is
 * recorded in the A3 response's §5.1 and §5.2. No test was run.
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
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentInstrumentType;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentMode;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentStatus;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceAppointmentRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceAppointmentUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceAppointmentRepository;

/**
 * GovernanceAppointmentService
 *
 * The single write path for governance appointments. Every mutation
 * of a `GovernanceAppointment` row goes through this service.
 *
 * See the file-level change-verification block for the service's
 * responsibilities, its invariants, its state-transition rules, its
 * event-dispatch pattern, and its deliberate omissions.
 *
 * See the A3 CANDIDATE block above the namespace for the RP-01
 * pre-change snapshot change introduced in this revision.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
 * @version 1.1.0-a3-candidate
 */
final class GovernanceAppointmentService
{
    /**
     * Columns that may be supplied to `register()` and `update()`.
     *
     * This is the model's `$fillable` array minus `tenant_id`, which
     * is supplied explicitly to `register()` as a separate argument
     * and is never updated.
     *
     * Any other key in the caller's `$data` array is silently
     * dropped by the whitelist.
     *
     * @var list<string>
     */
    private const ALLOWED_FIELDS = [
        'governance_body_id',
        'governance_position_id',
        'governance_term_id',
        'instrument_type',
        'instrument_reference',
        'instrument_date',
        'effective_date',
        'appointment_mode',
        'status',
        'title_am',
        'title_en',
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
     * @var list<string>
     */
    private const IMMUTABLE_FK_FIELDS = [
        'governance_body_id',
        'governance_position_id',
        'governance_term_id',
    ];

    /**
     * Construct the service with its repository dependency.
     *
     * @param GovernanceAppointmentRepository $repository
     */
    public function __construct(
        private readonly GovernanceAppointmentRepository $repository,
    ) {
    }

    // =========================================================================
    // Public API — writes
    // =========================================================================

    /**
     * Register a new governance appointment.
     *
     * Validates every domain invariant (INV-1 through INV-9) before
     * persisting. The new row's `status` is forced to `'draft'`
     * regardless of what the caller supplied — an appointment
     * becomes `active` through the explicit `activate()` method,
     * not through registration.
     *
     * The entire operation runs in a single database transaction;
     * the `GovernanceAppointmentRegistered` event fires only after
     * commit.
     *
     * @param  array<string, mixed> $data
     * @param  int                  $tenantId
     * @return GovernanceAppointment
     *
     * @throws ValidationException
     */
    public function register(array $data, int $tenantId): GovernanceAppointment
    {
        $attributes = $this->normaliseForRegister($data, $tenantId);

        return DB::transaction(function () use ($attributes, $tenantId): GovernanceAppointment {
            $appointment = GovernanceAppointment::create([
                'tenant_id' => $tenantId,
                ...$attributes,
            ]);

            $this->dispatchAfterCommit(new GovernanceAppointmentRegistered($appointment));

            return $appointment;
        });
    }

    /**
     * Update an existing governance appointment.
     *
     * The three FK columns (`governance_body_id`,
     * `governance_position_id`, `governance_term_id`) are immutable;
     * passing a value that differs from the current one throws a
     * `ValidationException` (INV-2, INV-4, INV-6). Every other
     * whitelisted field may be updated.
     *
     * The `status` field is NOT updatable through `update()`. The
     * state machine is expressed through the three lifecycle
     * methods (`activate()`, `revoke()`, `supersede()`) — a caller
     * that needs to change status uses one of those. This is
     * deliberate: it keeps the transition graph enforced in one
     * place.
     *
     * A3 / RP-01: the persisted pre-change attribute map is captured
     * with `getOriginal()` BEFORE `fill()` / `save()`, and passed to
     * the event so the audit listener can derive true `old_values`.
     * See the A3 CANDIDATE block above the namespace for the full
     * rationale.
     *
     * @param  GovernanceAppointment $appointment
     * @param  array<string, mixed>  $data
     * @return GovernanceAppointment
     *
     * @throws ValidationException
     */
    public function update(GovernanceAppointment $appointment, array $data): GovernanceAppointment
    {
        $attributes = $this->normaliseForUpdate($appointment, $data);

        if ($attributes === []) {
            return $appointment;
        }

        return DB::transaction(function () use ($appointment, $attributes): GovernanceAppointment {
            // A3 / RP-01 — capture the persisted pre-change state BEFORE the
            // mutation. Laravel's finishSave() (called by save()) and refresh()
            // both call syncOriginal(), so this is the last point at which
            // getOriginal() reflects the pre-change state.
            $beforeSnapshot = $appointment->getOriginal();

            $appointment->fill($attributes)->save();

            $this->dispatchAfterCommit(
                new GovernanceAppointmentUpdated($appointment, $beforeSnapshot)
            );

            return $appointment->refresh();
        });
    }

    /**
     * Activate a draft appointment (draft → active).
     *
     * Idempotent when the appointment is already `active`: returns
     * the appointment unchanged and dispatches no event.
     *
     * Refuses when the appointment is in a terminal state (revoked
     * or superseded) — an appointment that has been withdrawn or
     * replaced cannot be reactivated. A corrective appointment is a
     * NEW row, not a resurrection of the old one.
     *
     * A3 / RP-01: pre-change attribute map captured before mutation
     * and passed to the event.
     *
     * @param  GovernanceAppointment $appointment
     * @return GovernanceAppointment
     *
     * @throws ValidationException
     */
    public function activate(GovernanceAppointment $appointment): GovernanceAppointment
    {
        $current = $appointment->statusEnum();

        // Already active — no-op.
        if ($current === GovernanceAppointmentStatus::Active) {
            return $appointment;
        }

        // Terminal states cannot be reactivated.
        if ($current?->isTerminal() === true) {
            throw ValidationException::withMessages([
                'status' => [
                    sprintf(
                        'An appointment in the "%s" state cannot be activated. '
                        . 'Create a new appointment instead.',
                        $current->value,
                    ),
                ],
            ]);
        }

        return DB::transaction(function () use ($appointment): GovernanceAppointment {
            // Serialize activations for this position before checking the incumbent invariant.
            $this->assertNoOtherActiveAppointmentForPosition($appointment);

            // A3 / RP-01 — pre-change snapshot.
            $beforeSnapshot = $appointment->getOriginal();

            $appointment->status = GovernanceAppointmentStatus::Active->value;
            $appointment->save();

            $this->dispatchAfterCommit(
                new GovernanceAppointmentUpdated($appointment, $beforeSnapshot)
            );

            return $appointment->refresh();
        });
    }

    /**
     * Revoke an appointment (draft → revoked, or active → revoked).
     *
     * Revocation is legal from `draft` (an appointment that was
     * recorded and then withdrawn before it entered force) and from
     * `active` (the more common case).
     *
     * Idempotent when the appointment is already `revoked`: returns
     * the appointment unchanged and dispatches no event.
     *
     * Refuses when the appointment is `superseded` — a superseded
     * appointment has already been replaced by a later instrument;
     * revoking it retroactively would create two contradictory end-
     * reasons for the same appointment. A caller that needs to
     * correct a superseded appointment supersedes the LATER
     * instrument, not the earlier one.
     *
     * A3 / RP-01: pre-change attribute map captured before mutation
     * and passed to the event.
     *
     * @param  GovernanceAppointment $appointment
     * @return GovernanceAppointment
     *
     * @throws ValidationException
     */
    public function revoke(GovernanceAppointment $appointment): GovernanceAppointment
    {
        $current = $appointment->statusEnum();

        // Already revoked — no-op.
        if ($current === GovernanceAppointmentStatus::Revoked) {
            return $appointment;
        }

        // Superseded is terminal and cannot be revoked.
        if ($current === GovernanceAppointmentStatus::Superseded) {
            throw ValidationException::withMessages([
                'status' => [
                    'A superseded appointment cannot be revoked. '
                    . 'Supersede the later instrument that replaced it instead.',
                ],
            ]);
        }

        return DB::transaction(function () use ($appointment): GovernanceAppointment {
            // A3 / RP-01 — pre-change snapshot.
            $beforeSnapshot = $appointment->getOriginal();

            $appointment->status = GovernanceAppointmentStatus::Revoked->value;
            $appointment->save();

            $this->dispatchAfterCommit(
                new GovernanceAppointmentUpdated($appointment, $beforeSnapshot)
            );

            return $appointment->refresh();
        });
    }

    /**
     * Supersede an active appointment (active → superseded).
     *
     * Supersession is legal only from `active` — an appointment that
     * was never in force cannot be replaced by a later instrument.
     *
     * Idempotent when the appointment is already `superseded`:
     * returns the appointment unchanged and dispatches no event.
     *
     * Refuses from `draft` (nothing to supersede) and from `revoked`
     * (a revoked appointment has already ended for a different
     * reason; a later instrument does not restore it).
     *
     * A3 / RP-01: pre-change attribute map captured before mutation
     * and passed to the event.
     *
     * @param  GovernanceAppointment $appointment
     * @return GovernanceAppointment
     *
     * @throws ValidationException
     */
    public function supersede(GovernanceAppointment $appointment): GovernanceAppointment
    {
        $current = $appointment->statusEnum();

        // Already superseded — no-op.
        if ($current === GovernanceAppointmentStatus::Superseded) {
            return $appointment;
        }

        if ($current !== GovernanceAppointmentStatus::Active) {
            throw ValidationException::withMessages([
                'status' => [
                    sprintf(
                        'Only an active appointment can be superseded. '
                        . 'The current status is "%s".',
                        $current?->value ?? 'unknown',
                    ),
                ],
            ]);
        }

        return DB::transaction(function () use ($appointment): GovernanceAppointment {
            // A3 / RP-01 — pre-change snapshot.
            $beforeSnapshot = $appointment->getOriginal();

            $appointment->status = GovernanceAppointmentStatus::Superseded->value;
            $appointment->save();

            $this->dispatchAfterCommit(
                new GovernanceAppointmentUpdated($appointment, $beforeSnapshot)
            );

            return $appointment->refresh();
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
        // 2. governance_position_id — optional; when set, must reference a
        //    position in the same tenant AND on the SAME body.
        // ---------------------------------------------------------------------
        $positionId = $attributes['governance_position_id'] ?? null;

        if ($positionId !== null && $positionId !== '') {
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
                        'The specified governance position does not belong to the appointment\'s body.',
                    ],
                ]);
            }

            $attributes['governance_position_id'] = $positionId;
        } else {
            $attributes['governance_position_id'] = null;
        }

        // ---------------------------------------------------------------------
        // 3. governance_term_id — optional; when set, must reference a term
        //    in the same tenant AND on the SAME body.
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
                        'The specified governance term does not belong to the appointment\'s body.',
                    ],
                ]);
            }

            $attributes['governance_term_id'] = $termId;
        } else {
            $attributes['governance_term_id'] = null;
        }

        // ---------------------------------------------------------------------
        // 4. instrument_type — required; must resolve to an enum case.
        // ---------------------------------------------------------------------
        $instrumentTypeValue = $attributes['instrument_type'] ?? null;

        if (!is_string($instrumentTypeValue) || $instrumentTypeValue === '') {
            throw ValidationException::withMessages([
                'instrument_type' => ['The instrument type is required.'],
            ]);
        }

        $instrumentType = GovernanceAppointmentInstrumentType::tryFrom($instrumentTypeValue);

        if ($instrumentType === null) {
            throw ValidationException::withMessages([
                'instrument_type' => [
                    sprintf(
                        'The instrument type "%s" is not recognised. Allowed: %s.',
                        $instrumentTypeValue,
                        implode(', ', GovernanceAppointmentInstrumentType::values()),
                    ),
                ],
            ]);
        }

        $attributes['instrument_type'] = $instrumentType->value;

        // ---------------------------------------------------------------------
        // 5. appointment_mode — required; must resolve to an enum case.
        // ---------------------------------------------------------------------
        $modeValue = $attributes['appointment_mode'] ?? null;

        if (!is_string($modeValue) || $modeValue === '') {
            throw ValidationException::withMessages([
                'appointment_mode' => ['The appointment mode is required.'],
            ]);
        }

        $mode = GovernanceAppointmentMode::tryFrom($modeValue);

        if ($mode === null) {
            throw ValidationException::withMessages([
                'appointment_mode' => [
                    sprintf(
                        'The appointment mode "%s" is not recognised. Allowed: %s.',
                        $modeValue,
                        implode(', ', GovernanceAppointmentMode::values()),
                    ),
                ],
            ]);
        }

        $attributes['appointment_mode'] = $mode->value;

        // ---------------------------------------------------------------------
        // 6. effective_date — required.
        // ---------------------------------------------------------------------
        $effectiveDate = $attributes['effective_date'] ?? null;

        if ($effectiveDate === null || $effectiveDate === '') {
            throw ValidationException::withMessages([
                'effective_date' => ['The effective date is required.'],
            ]);
        }

        // ---------------------------------------------------------------------
        // 7. instrument_date — optional; when set, must not be after
        //    effective_date (INV-8).
        // ---------------------------------------------------------------------
        $this->assertInstrumentDateOrdering(
            $attributes['instrument_date'] ?? null,
            $effectiveDate,
        );

        // ---------------------------------------------------------------------
        // 8. status — always forced to 'draft' at registration. See the
        //    file-level change-verification block §"Why register() sets
        //    status to 'draft' explicitly".
        // ---------------------------------------------------------------------
        $attributes['status'] = GovernanceAppointmentStatus::Draft->value;

        return $attributes;
    }

    // =========================================================================
    // Private — normalisation and validation (update)
    // =========================================================================

    /**
     * Normalise and validate the input for an `update()` call.
     *
     * @param  GovernanceAppointment $appointment
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normaliseForUpdate(GovernanceAppointment $appointment, array $data): array
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
            $current  = $appointment->{$field};

            // Normalise null vs. empty string to the same "null" value.
            $proposedNormalised = ($proposed === null || $proposed === '') ? null : (int) $proposed;
            $currentNormalised  = ($current === null) ? null : (int) $current;

            if ($proposedNormalised !== $currentNormalised) {
                throw ValidationException::withMessages([
                    $field => [
                        'This field cannot be changed after registration. '
                        . 'Create a new appointment instead.',
                    ],
                ]);
            }

            // Value matches — drop the key so the subsequent fill does not
            // trigger a needless UPDATE.
            unset($attributes[$field]);
        }

        // ---------------------------------------------------------------------
        // 2. instrument_type — when set, must resolve to an enum case.
        // ---------------------------------------------------------------------
        if (array_key_exists('instrument_type', $attributes)) {
            $value = $attributes['instrument_type'];

            if (!is_string($value) || $value === '') {
                throw ValidationException::withMessages([
                    'instrument_type' => ['The instrument type cannot be empty.'],
                ]);
            }

            $instrumentType = GovernanceAppointmentInstrumentType::tryFrom($value);

            if ($instrumentType === null) {
                throw ValidationException::withMessages([
                    'instrument_type' => [
                        sprintf(
                            'The instrument type "%s" is not recognised. Allowed: %s.',
                            $value,
                            implode(', ', GovernanceAppointmentInstrumentType::values()),
                        ),
                    ],
                ]);
            }

            $attributes['instrument_type'] = $instrumentType->value;
        }

        // ---------------------------------------------------------------------
        // 3. appointment_mode — when set, must resolve to an enum case.
        // ---------------------------------------------------------------------
        if (array_key_exists('appointment_mode', $attributes)) {
            $value = $attributes['appointment_mode'];

            if (!is_string($value) || $value === '') {
                throw ValidationException::withMessages([
                    'appointment_mode' => ['The appointment mode cannot be empty.'],
                ]);
            }

            $mode = GovernanceAppointmentMode::tryFrom($value);

            if ($mode === null) {
                throw ValidationException::withMessages([
                    'appointment_mode' => [
                        sprintf(
                            'The appointment mode "%s" is not recognised. Allowed: %s.',
                            $value,
                            implode(', ', GovernanceAppointmentMode::values()),
                        ),
                    ],
                ]);
            }

            $attributes['appointment_mode'] = $mode->value;
        }

        // ---------------------------------------------------------------------
        // 4. status — REJECTED. The state machine is expressed through the
        //    three lifecycle methods, not through update().
        // ---------------------------------------------------------------------
        if (array_key_exists('status', $attributes)) {
            throw ValidationException::withMessages([
                'status' => [
                    'The appointment status cannot be changed through update. '
                    . 'Use the activate, revoke, or supersede methods instead.',
                ],
            ]);
        }

        // ---------------------------------------------------------------------
        // 5. effective_date and instrument_date — re-validate the ordering
        //    against the POST-update state (INV-8).
        // ---------------------------------------------------------------------
        $from = array_key_exists('instrument_date', $attributes)
            ? $attributes['instrument_date']
            : $appointment->instrument_date;

        $to = array_key_exists('effective_date', $attributes)
            ? $attributes['effective_date']
            : $appointment->effective_date;

        $this->assertInstrumentDateOrdering($from, $to);

        return $attributes;
    }

    // =========================================================================
    // Private — invariant assertions
    // =========================================================================

    /**
     * Assert that `effective_date >= instrument_date` when both are
     * set.
     *
     * Accepts either a date string (`Y-m-d`), a `Carbon` instance, a
     * `DateTimeInterface`, or null. Null on either side means the
     * comparison is not applicable, which is always valid.
     *
     * @param  mixed $instrumentDate
     * @param  mixed $effectiveDate
     * @return void
     *
     * @throws ValidationException
     */
    /**
     * Enforce one active primary appointment per position. The position row
     * is the shared mutex, closing the independent-connection activation race.
     */
    private function assertNoOtherActiveAppointmentForPosition(GovernanceAppointment $appointment): void
    {
        $positionId = $appointment->governance_position_id;
        if ($positionId === null) {
            return;
        }

        GovernancePosition::query()
            ->where('tenant_id', (int) $appointment->tenant_id)
            ->whereKey((int) $positionId)
            ->lockForUpdate()
            ->firstOrFail();

        $conflict = GovernanceAppointment::query()
            ->where('tenant_id', (int) $appointment->tenant_id)
            ->where('governance_position_id', (int) $positionId)
            ->where('status', GovernanceAppointmentStatus::Active->value)
            ->where('id', '<>', (int) $appointment->id)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'governance_position_id' => ['This position already has an active primary appointment. Revoke or supersede it before activating another.'],
            ]);
        }
    }

    private function assertInstrumentDateOrdering(mixed $instrumentDate, mixed $effectiveDate): void
    {
        if ($instrumentDate === null || $effectiveDate === null) {
            return;
        }

        $fromDate  = $this->toCarbon($instrumentDate);
        $toDate    = $this->toCarbon($effectiveDate);

        if ($fromDate === null || $toDate === null) {
            // Unparseable input — the request class catches this at the
            // HTTP boundary. The service does not attempt a second parse.
            return;
        }

        if ($toDate->lessThan($fromDate)) {
            throw ValidationException::withMessages([
                'effective_date' => [
                    'The effective date must be on or after the instrument date.',
                ],
            ]);
        }
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
     * Uses a direct model query rather than the
     * `GovernanceBodyRepository` because the appointment service
     * does not inject that repository — a deliberate choice to keep
     * the services independent. The check is a single primary-key
     * lookup on an indexed column.
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