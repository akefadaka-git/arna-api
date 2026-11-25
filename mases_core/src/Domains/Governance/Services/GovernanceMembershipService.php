<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use DateTimeInterface;
use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceMembershipStatus;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceMembershipRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceMembershipUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceMembershipRepository;

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
            // Serialize overlap checks for this position across independent connections.
            $this->lockPositionForOverlapCheck($tenantId, (int) $attributes['governance_position_id']);
            $this->assertNoOverlap(
                tenantId:          $tenantId,
                bodyId:            (int) $attributes['governance_body_id'],
                positionId:        (int) $attributes['governance_position_id'],
                personId:          $attributes['person_id'] ?? null,
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
                $this->lockPositionForOverlapCheck((int) $membership->tenant_id, (int) $membership->governance_position_id);
                $this->assertNoOverlap(
                    tenantId:          (int) $membership->tenant_id,
                    bodyId:            (int) $membership->governance_body_id,
                    positionId:        (int) $membership->governance_position_id,
                    personId:          $membership->person_id === null ? null : (int) $membership->person_id,
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

    /** Lock the stable position row before overlap queries. */
    private function lockPositionForOverlapCheck(int $tenantId, int $positionId): void
    {
        GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($positionId)
            ->lockForUpdate()
            ->firstOrFail();
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
        ?int $personId,
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
            personId:          $personId,
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