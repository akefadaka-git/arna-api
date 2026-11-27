<?php

declare(strict_types=1);

// Enforce tenant, body/position/term consistency and lifecycle invariants.



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
 * @package MaaleArna\Arna\Core\Domains\Governance\Services
 * @version 1.1.0-a3-candidate
 * =============================================================================
 */

namespace MaaleArna\Arna\Core\Domains\Governance\Services;

use DateTimeInterface;
use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceAppointmentInstrumentType;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceAppointmentMode;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceAppointmentStatus;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceAppointmentRegistered;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceAppointmentUpdated;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\Arna\Core\Domains\Governance\Repositories\GovernanceAppointmentRepository;

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
 * @package MaaleArna\Arna\Core\Domains\Governance\Services
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
            // Appointment lifecycle is not occupancy. Future instruments may be activated
            // before their effective date; exclusivity is enforced by explicit dated seat occupancies.

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