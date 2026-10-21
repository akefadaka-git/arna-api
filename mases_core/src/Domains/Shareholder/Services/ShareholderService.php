<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\ShareholderType;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\ShareholderRegistered;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\ShareholderUpdated;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Shareholder\Repositories\ShareholderRepository;

/**
 * ShareholderService
 *
 * The single write path for shareholder identity in the Shareholder domain.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * Every mutation of a `Shareholder` row goes through this service. The
 * service:
 *
 *   1. Normalises the caller's input to the shape the model accepts.
 *
 *   2. Validates the domain invariants that must hold before persist:
 *        - The shareholder type is one of the six permitted by D9.
 *        - The required name fields are present for the type.
 *        - A legal-entity shareholder has a representative (D11).
 *        - A legal-entity shareholder does not have a direct user_id (D11).
 *        - An individual shareholder's user_id is unique within the tenant.
 *
 *   3. Persists the change inside a database transaction (Section 38).
 *
 *   4. Dispatches the corresponding domain event AFTER commit, so a listener
 *      that inspects the persisted row cannot see a half-written state.
 *
 * The service does NOT perform authorization. That is the controller's
 * responsibility (invoke the policy) and the policy's decision (2A.29).
 *
 * =============================================================================
 * Type immutability
 * =============================================================================
 * A shareholder's `shareholder_type` is set at registration and cannot be
 * changed through `update()`. The type determines which name columns are
 * meaningful:
 *
 *     individual → first_name_* / father_name_* / grandfather_name_*
 *     legal entity → entity_name_*
 *
 * Changing the type in place would invalidate the identity columns on an
 * existing row and would confuse every historical ledger entry that
 * references this shareholder. If a genuine type change is ever needed,
 * it must be a Change Request that:
 *   - defines the semantics of the change (creation of a new row? in-place
 *     conversion with name migration? both shareholders retained?)
 *   - specifies the audit trail
 *   - specifies how the linked holdings and certificates are handled
 *
 * Until then, `update()` rejects any `shareholder_type` key whose value
 * differs from the current type.
 *
 * =============================================================================
 * Representative rule (D11)
 * =============================================================================
 * A legal-entity shareholder (any type except `individual`) MUST have a
 * `representative_user_id` set at all times. This service enforces that
 * requirement in two places:
 *
 *   1. During `register()` — for non-individuals, the field must be present
 *      and must reference an existing User.
 *
 *   2. During `update()` and `assignRepresentative()` — the field may be
 *      changed, but may not be cleared on a non-individual. Setting it to
 *      null on a legal entity throws a ValidationException.
 *
 * An individual shareholder may optionally have a `representative_user_id`
 * (D11 permits representation of individuals too). For individuals, setting
 * the field to null is permitted.
 *
 * =============================================================================
 * Event dispatch semantics
 * =============================================================================
 * Events are dispatched via `DB::afterCommit()`, which:
 *
 *   - Registers a callback that runs after the outermost transaction commits.
 *   - Runs immediately if no transaction is active (defensive — should not
 *     occur inside this service, because every mutation is wrapped).
 *
 * If the transaction rolls back, the callback is discarded and no event
 * fires. This guarantees that a listener (2A.35, AuditShareholderEvent)
 * never sees a shareholder row that does not exist.
 *
 * The dispatch is gated on `config('shareholder.audit.emit_events')`. When
 * this config is false, the events are not dispatched at all. This is the
 * operator's switch for suppressing the audit trail during a maintenance
 * window. It defaults to true.
 *
 * =============================================================================
 * What this service does NOT do
 * =============================================================================
 *   - It does not perform authorization. Policies are invoked by the
 *     controller (2A.21) before this service is called.
 *
 *   - It does not change share balances. Those flow through
 *     ShareHoldingService (2A.14).
 *
 *   - It does not issue certificates. Those flow through CertificateService
 *     (2A.15).
 *
 *   - It does not handle KYC. The D12 field matrix is pending; no KYC field
 *     is accepted here.
 *
 *   - It does not soft-delete. `deactivate()` sets `is_active = false`; the
 *     row remains for historical reference. A future CR can add a
 *     `softDelete()` operation if the business requires it.
 *
 *   - It does not restore soft-deleted shareholders. Soft-deleted rows are
 *     hidden from default queries; the service has no "undelete" path
 *     because Phase 2A does not soft-delete through this service.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Services
 */
final class ShareholderService
{
    /**
     * Columns that may be supplied to `register()` and `update()`.
     *
     * This is the union of the `$fillable` on the Shareholder model plus
     * `shareholder_type` (which is set only at register time and validated
     * during update). Any other key in the caller's `$data` array is
     * silently dropped.
     *
     * @var list<string>
     */
    private const ALLOWED_FIELDS = [
        'shareholder_type',
        'first_name_am',
        'first_name_en',
        'father_name_am',
        'father_name_en',
        'grandfather_name_am',
        'grandfather_name_en',
        'entity_name_am',
        'entity_name_en',
        'user_id',
        'representative_user_id',
        'is_active',
    ];

    /**
     * Construct the service with its repository dependency.
     *
     * @param ShareholderRepository $repository
     */
    public function __construct(
        private readonly ShareholderRepository $repository,
    ) {
    }

    // =========================================================================
    // Public API — writes
    // =========================================================================

    /**
     * Register a new shareholder.
     *
     * Validates every domain invariant (D8, D9, D11) before persisting. The
     * entire operation runs in a single database transaction; the
     * `ShareholderRegistered` event fires only after commit.
     *
     * @param array<string, mixed> $data      Input fields, whitelisted against ALLOWED_FIELDS.
     * @param int                  $tenantId  The owning tenant, resolved from server-side context.
     * @return Shareholder
     *
     * @throws ValidationException
     */
    public function register(array $data, int $tenantId): Shareholder
    {
        $attributes = $this->normaliseForRegister($data, $tenantId);

        return DB::transaction(function () use ($attributes, $tenantId): Shareholder {
            $now = now();

            // Fail closed until every existing shareholder in this tenant has a reconciled
            // business number. Otherwise a fresh sequence could reuse a number belonging
            // to an unnumbered legacy record. The approved backfill must run first.
            if (DB::table('shareholders')
                ->where('tenant_id', $tenantId)
                ->whereNull('shareholder_number')
                ->exists()) {
                throw new \\LogicException(
                    'Shareholder numbering is not reconciled for this tenant; registration is temporarily blocked.'
                );
            }

            DB::table('shareholder_number_sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'next_number' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sequence = DB::table('shareholder_number_sequences')
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw new \LogicException(
                    'Unable to initialize the shareholder number sequence.'
                );
            }

            $numberSequence = (int) $sequence->next_number;

            if ($numberSequence < 1 || $numberSequence >= PHP_INT_MAX) {
                throw new \OverflowException(
                    'The shareholder number sequence is invalid or exhausted.'
                );
            }

            $shareholderNumber = 'SH-' . str_pad(
                (string) $numberSequence,
                6,
                '0',
                STR_PAD_LEFT,
            );

            $updated = DB::table('shareholder_number_sequences')
                ->where('tenant_id', $tenantId)
                ->update([
                    'next_number' => $numberSequence + 1,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new \LogicException(
                    'Unable to advance the shareholder number sequence.'
                );
            }

            $shareholder = Shareholder::create([
                'tenant_id' => $tenantId,
                ...$attributes,
            ]);

            $shareholder->forceFill([
                'shareholder_number' => $shareholderNumber,
            ]);
            $shareholder->saveOrFail();

            DB::table('shareholder_number_reservations')->insert([
                'tenant_id' => $tenantId,
                'number_sequence' => $numberSequence,
                'shareholder_number' => $shareholderNumber,
                'shareholder_id' => $shareholder->getKey(),
                'reserved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->dispatchAfterCommit(
                new ShareholderRegistered($shareholder)
            );

            return $shareholder;
        });
    }

    /**
     * Update an existing shareholder.
     *
     * The shareholder type is immutable; passing a `shareholder_type` key
     * whose value differs from the current type throws a ValidationException.
     * All other whitelisted fields may be updated.
     *
     * @param Shareholder          $shareholder  The row to update.
     * @param array<string, mixed> $data
     * @return Shareholder
     *
     * @throws ValidationException
     */
    public function update(Shareholder $shareholder, array $data): Shareholder
    {
        $attributes = $this->normaliseForUpdate($shareholder, $data);

        if ($attributes === []) {
            return $shareholder;
        }

        return DB::transaction(function () use ($shareholder, $attributes): Shareholder {
            $shareholder->fill($attributes)->save();

            $this->dispatchAfterCommit(new ShareholderUpdated($shareholder));

            return $shareholder->refresh();
        });
    }

    /**
     * Deactivate a shareholder (set `is_active = false`).
     *
     * The row is not deleted. Historical ledger entries, certificates, and
     * audit rows continue to reference it. A deactivated shareholder is
     * excluded from "active" queries but remains resolvable by id.
     *
     * @param Shareholder $shareholder
     * @return Shareholder
     */
    public function deactivate(Shareholder $shareholder): Shareholder
    {
        if ($shareholder->is_active === false) {
            return $shareholder;
        }

        return DB::transaction(function () use ($shareholder): Shareholder {
            $shareholder->is_active = false;
            $shareholder->save();

            $this->dispatchAfterCommit(new ShareholderUpdated($shareholder));

            return $shareholder->refresh();
        });
    }

    /**
     * Reactivate a previously deactivated shareholder.
     *
     * @param Shareholder $shareholder
     * @return Shareholder
     */
    public function reactivate(Shareholder $shareholder): Shareholder
    {
        if ($shareholder->is_active === true) {
            return $shareholder;
        }

        return DB::transaction(function () use ($shareholder): Shareholder {
            $shareholder->is_active = true;
            $shareholder->save();

            $this->dispatchAfterCommit(new ShareholderUpdated($shareholder));

            return $shareholder->refresh();
        });
    }

    /**
     * Assign or change the representative User for a shareholder.
     *
     * Intended for:
     *   - Changing the representative of a legal-entity shareholder.
     *   - Assigning a representative to an individual who has none.
     *
     * The service does not check whether the shareholder's type permits a
     * representative — D11 permits representation for every type. The
     * controller/policy layer is responsible for deciding whether the
     * caller has authority to perform this operation.
     *
     * @param Shareholder $shareholder
     * @param User        $representative
     * @return Shareholder
     *
     * @throws ValidationException
     */
    public function assignRepresentative(Shareholder $shareholder, User $representative): Shareholder
    {
        // Defensive: the representative User must be persisted. Transient
        // User models (constructed but not saved) have no id and would
        // produce a null foreign key.
        if (!$representative->exists) {
            throw ValidationException::withMessages([
                'representative_user_id' => [
                    'The representative user must be a persisted user record.',
                ],
            ]);
        }

        if ($shareholder->representative_user_id === $representative->id) {
            return $shareholder;
        }

        return DB::transaction(function () use ($shareholder, $representative): Shareholder {
            $shareholder->representative_user_id = $representative->id;
            $shareholder->save();

            $this->dispatchAfterCommit(new ShareholderUpdated($shareholder));

            return $shareholder->refresh();
        });
    }

    /**
     * Clear the representative User for a shareholder.
     *
     * Only permitted for individual shareholders. A legal-entity shareholder
     * MUST retain a representative (D11) — clearing it would violate the
     * domain invariant, so this method throws for non-individuals.
     *
     * @param Shareholder $shareholder
     * @return Shareholder
     *
     * @throws ValidationException
     */
    public function clearRepresentative(Shareholder $shareholder): Shareholder
    {
        if ($shareholder->requiresRepresentative()) {
            throw ValidationException::withMessages([
                'representative_user_id' => [
                    'A legal-entity shareholder must retain a designated representative.',
                ],
            ]);
        }

        if ($shareholder->representative_user_id === null) {
            return $shareholder;
        }

        return DB::transaction(function () use ($shareholder): Shareholder {
            $shareholder->representative_user_id = null;
            $shareholder->save();

            $this->dispatchAfterCommit(new ShareholderUpdated($shareholder));

            return $shareholder->refresh();
        });
    }

    // =========================================================================
    // Private — normalisation and validation
    // =========================================================================

    /**
     * Normalise and validate the input for a `register()` call.
     *
     * Returns the final attribute array to pass to `Shareholder::create()`.
     *
     * @param array<string, mixed> $data
     * @param int                  $tenantId
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normaliseForRegister(array $data, int $tenantId): array
    {
        $attributes = $this->whitelist($data);

        // ---------------------------------------------------------------------
        // 1. shareholder_type
        // ---------------------------------------------------------------------
        $typeValue = $attributes['shareholder_type'] ?? null;

        if (!is_string($typeValue) || $typeValue === '') {
            throw ValidationException::withMessages([
                'shareholder_type' => ['The shareholder type is required.'],
            ]);
        }

        $type = ShareholderType::tryFrom($typeValue);

        if ($type === null) {
            throw ValidationException::withMessages([
                'shareholder_type' => [
                    sprintf(
                        'The shareholder type "%s" is not recognised.',
                        $typeValue,
                    ),
                ],
            ]);
        }

        // ---------------------------------------------------------------------
        // 2. Names
        // ---------------------------------------------------------------------
        if ($type === ShareholderType::Individual) {
            $this->requireIndividualName($attributes);

            // Drop any entity-name columns that were supplied for an
            // individual. They would be meaningless on this row.
            unset(
                $attributes['entity_name_am'],
                $attributes['entity_name_en'],
            );
        } else {
            $this->requireEntityName($attributes);

            // Drop the six personal-name columns for a legal entity. They
            // would be meaningless on this row.
            unset(
                $attributes['first_name_am'],
                $attributes['first_name_en'],
                $attributes['father_name_am'],
                $attributes['father_name_en'],
                $attributes['grandfather_name_am'],
                $attributes['grandfather_name_en'],
            );
        }

        // ---------------------------------------------------------------------
        // 3. user_id — direct self-service link
        // ---------------------------------------------------------------------
        if (array_key_exists('user_id', $attributes) && $attributes['user_id'] !== null) {
            // A direct user_id is only meaningful for individuals.
            if ($type !== ShareholderType::Individual) {
                throw ValidationException::withMessages([
                    'user_id' => [
                        'A legal-entity shareholder cannot have a direct user account. '
                        . 'Use representative_user_id instead.',
                    ],
                ]);
            }

            $userId = (int) $attributes['user_id'];

            if (!User::whereKey($userId)->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => ['The specified user does not exist.'],
                ]);
            }

            if ($this->repository->isUserLinked($tenantId, $userId)) {
                throw ValidationException::withMessages([
                    'user_id' => [
                        'The specified user is already linked to another shareholder in this tenant.',
                    ],
                ]);
            }

            $attributes['user_id'] = $userId;
        } else {
            $attributes['user_id'] = null;
        }

        // ---------------------------------------------------------------------
        // 4. representative_user_id
        // ---------------------------------------------------------------------
        $repId = $attributes['representative_user_id'] ?? null;

        if ($type->requiresRepresentative()) {
            if ($repId === null) {
                throw ValidationException::withMessages([
                    'representative_user_id' => [
                        'A legal-entity shareholder requires a designated representative user.',
                    ],
                ]);
            }

            if (!User::whereKey((int) $repId)->exists()) {
                throw ValidationException::withMessages([
                    'representative_user_id' => [
                        'The specified representative user does not exist.',
                    ],
                ]);
            }

            $attributes['representative_user_id'] = (int) $repId;
        } else {
            // Individual: representative is optional. Validate only if set.
            if ($repId !== null) {
                if (!User::whereKey((int) $repId)->exists()) {
                    throw ValidationException::withMessages([
                        'representative_user_id' => [
                            'The specified representative user does not exist.',
                        ],
                    ]);
                }

                $attributes['representative_user_id'] = (int) $repId;
            } else {
                $attributes['representative_user_id'] = null;
            }
        }

        // ---------------------------------------------------------------------
        // 5. is_active default
        // ---------------------------------------------------------------------
        if (!array_key_exists('is_active', $attributes) || $attributes['is_active'] === null) {
            $attributes['is_active'] = true;
        } else {
            $attributes['is_active'] = (bool) $attributes['is_active'];
        }

        return $attributes;
    }

    /**
     * Normalise and validate the input for an `update()` call.
     *
     * Rejects `shareholder_type` changes. Validates every other whitelisted
     * field against the same invariants used at registration.
     *
     * @param Shareholder          $shareholder
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normaliseForUpdate(Shareholder $shareholder, array $data): array
    {
        $attributes = $this->whitelist($data);

        // ---------------------------------------------------------------------
        // 1. shareholder_type — immutable
        // ---------------------------------------------------------------------
        if (array_key_exists('shareholder_type', $attributes)) {
            $proposed = $attributes['shareholder_type'];

            if ($proposed !== $shareholder->shareholder_type) {
                throw ValidationException::withMessages([
                    'shareholder_type' => [
                        'The shareholder type cannot be changed after registration.',
                    ],
                ]);
            }

            // Value matches — drop the key so the subsequent fill does not
            // trigger a needless UPDATE.
            unset($attributes['shareholder_type']);
        }

        // ---------------------------------------------------------------------
        // 2. user_id — validate uniqueness if being set
        // ---------------------------------------------------------------------
        if (array_key_exists('user_id', $attributes) && $attributes['user_id'] !== null) {
            if (!$shareholder->isIndividual()) {
                throw ValidationException::withMessages([
                    'user_id' => [
                        'A legal-entity shareholder cannot have a direct user account.',
                    ],
                ]);
            }

            $userId = (int) $attributes['user_id'];

            if (!User::whereKey($userId)->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => ['The specified user does not exist.'],
                ]);
            }

            if ($this->repository->isUserLinked($shareholder->tenant_id, $userId, $shareholder->id)) {
                throw ValidationException::withMessages([
                    'user_id' => [
                        'The specified user is already linked to another shareholder in this tenant.',
                    ],
                ]);
            }

            $attributes['user_id'] = $userId;
        } elseif (array_key_exists('user_id', $attributes)) {
            // Clearing the direct link is always permitted.
            $attributes['user_id'] = null;
        }

        // ---------------------------------------------------------------------
        // 3. representative_user_id — cannot be cleared on a non-individual
        // ---------------------------------------------------------------------
        if (array_key_exists('representative_user_id', $attributes)) {
            $repId = $attributes['representative_user_id'];

            if ($repId === null) {
                if ($shareholder->requiresRepresentative()) {
                    throw ValidationException::withMessages([
                        'representative_user_id' => [
                            'A legal-entity shareholder must retain a designated representative.',
                        ],
                    ]);
                }
            } else {
                if (!User::whereKey((int) $repId)->exists()) {
                    throw ValidationException::withMessages([
                        'representative_user_id' => [
                            'The specified representative user does not exist.',
                        ],
                    ]);
                }
                $attributes['representative_user_id'] = (int) $repId;
            }
        }

        // ---------------------------------------------------------------------
        // 4. is_active — cast to bool
        // ---------------------------------------------------------------------
        if (array_key_exists('is_active', $attributes)) {
            $attributes['is_active'] = (bool) $attributes['is_active'];
        }

        return $attributes;
    }

    /**
     * Require at least one individual name column to be non-empty.
     *
     * @param array<string, mixed> $attributes
     * @return void
     *
     * @throws ValidationException
     */
    private function requireIndividualName(array $attributes): void
    {
        $candidates = [
            $attributes['first_name_am'] ?? null,
            $attributes['first_name_en'] ?? null,
            $attributes['father_name_am'] ?? null,
            $attributes['father_name_en'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return;
            }
        }

        throw ValidationException::withMessages([
            'first_name_am' => [
                'At least one of first_name_am, first_name_en, father_name_am, or father_name_en is required.',
            ],
        ]);
    }

    /**
     * Require at least one legal-entity name column to be non-empty.
     *
     * @param array<string, mixed> $attributes
     * @return void
     *
     * @throws ValidationException
     */
    private function requireEntityName(array $attributes): void
    {
        $candidates = [
            $attributes['entity_name_am'] ?? null,
            $attributes['entity_name_en'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return;
            }
        }

        throw ValidationException::withMessages([
            'entity_name_am' => [
                'At least one of entity_name_am or entity_name_en is required.',
            ],
        ]);
    }

    /**
     * Restrict the caller's input to the whitelisted field set.
     *
     * Unknown keys are dropped. This prevents accidental mass-assignment of
     * fields such as `tenant_id`, `id`, `created_at`, or any future column
     * that is not intended to be caller-controlled.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function whitelist(array $data): array
    {
        return array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));
    }

    // =========================================================================
    // Private — event dispatch
    // =========================================================================

    /**
     * Dispatch an event after the current transaction commits, subject to
     * the audit feature flag.
     *
     * Uses `DB::afterCommit()`, which registers a callback that the
     * framework invokes once the outermost transaction commits. If no
     * transaction is active, the callback runs immediately — this is
     * defensive, because every mutation in this service is wrapped.
     *
     * @param object $event
     * @return void
     */
    private function dispatchAfterCommit(object $event): void
    {
        if (!config('shareholder.audit.emit_events', true)) {
            return;
        }

        DB::afterCommit(static function () use ($event): void {
            Event::dispatch($event);
        });
    }
}