<?php

declare(strict_types=1);


namespace MaaleArna\Arna\Core\Domains\Governance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernancePositionRegistered;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernancePositionUpdated;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\Arna\Core\Domains\Governance\Repositories\GovernancePositionRepository;

/**
 * GovernancePositionService
 *
 * The single write path for governance positions. Every mutation of
 * a `GovernancePosition` row goes through this service.
 *
 * See the file-level change-verification block for the service's
 * responsibilities, its invariants, its event-dispatch pattern, and
 * its deliberate omissions.
 *
 * @package MaaleArna\Arna\Core\Domains\Governance\Services
 * @version 1.0.0
 */
final class GovernancePositionService
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
        'code',
        'name_am',
        'name_en',
        'description_am',
        'description_en',
        'is_voting',
        'is_officer',
        'sort_order',
        'is_active',
    ];

    /**
     * The maximum length of a `code` value.
     *
     * Matches the column's VARCHAR(32) declaration.
     */
    private const MAX_CODE_LENGTH = 32;

    /**
     * The maximum value permitted for `sort_order`.
     *
     * Matches the `unsignedSmallInteger` column's upper bound.
     */
    private const MAX_SORT_ORDER = 65535;

    /**
     * Construct the service with its repository dependency.
     *
     * @param GovernancePositionRepository $repository
     */
    public function __construct(
        private readonly GovernancePositionRepository $repository,
    ) {
    }

    // =========================================================================
    // Public API — writes
    // =========================================================================

    /**
     * Register a new governance position.
     *
     * Validates every domain invariant (INV-1 through INV-6) before
     * persisting. The entire operation runs in a single database
     * transaction; the `GovernancePositionRegistered` event fires
     * only after commit.
     *
     * The `tenant_id` is passed explicitly as a separate argument —
     * it is NOT part of the caller's `$data` array. The controller
     * resolves the tenant from the server-side context and passes it
     * in.
     *
     * @param  array<string, mixed> $data
     * @param  int                  $tenantId
     * @return GovernancePosition
     *
     * @throws ValidationException
     */
    public function register(array $data, int $tenantId): GovernancePosition
    {
        $attributes = $this->normaliseForRegister($data, $tenantId);

        return DB::transaction(function () use ($attributes, $tenantId): GovernancePosition {
            $position = GovernancePosition::create([
                'tenant_id' => $tenantId,
                ...$attributes,
            ]);

            $this->dispatchAfterCommit(new GovernancePositionRegistered($position));

            return $position;
        });
    }

    /**
     * Update an existing governance position.
     *
     * The `governance_body_id` is immutable; passing a value that
     * differs from the current one throws a ValidationException
     * (INV-3). Every other whitelisted field may be updated,
     * including `code` — a position's code is a label, not a
     * structural fact.
     *
     * @param  GovernancePosition   $position
     * @param  array<string, mixed> $data
     * @return GovernancePosition
     *
     * @throws ValidationException
     */
    public function update(GovernancePosition $position, array $data): GovernancePosition
    {
        $attributes = $this->normaliseForUpdate($position, $data);

        if ($attributes === []) {
            return $position;
        }

        return DB::transaction(function () use ($position, $attributes): GovernancePosition {
            $before = $position->getOriginal();
            $position->fill($attributes)->save();
            $changes = $position->getChanges();

            $this->dispatchAfterCommit(new GovernancePositionUpdated($position, $before, $changes));

            return $position->refresh();
        });
    }

    /**
     * Deactivate a governance position (set `is_active = false`).
     *
     * Idempotent: deactivating an already-deactivated position is a
     * no-op that does not dispatch an event.
     *
     * @param  GovernancePosition $position
     * @return GovernancePosition
     */
    public function deactivate(GovernancePosition $position): GovernancePosition
    {
        if ($position->is_active === false) {
            return $position;
        }

        return DB::transaction(function () use ($position): GovernancePosition {
            $before = $position->getOriginal();
            $position->is_active = false;
            $position->save();
            $changes = $position->getChanges();

            $this->dispatchAfterCommit(new GovernancePositionUpdated($position, $before, $changes));

            return $position->refresh();
        });
    }

    /**
     * Reactivate a previously deactivated governance position.
     *
     * Idempotent: reactivating an already-active position is a no-op
     * that does not dispatch an event.
     *
     * @param  GovernancePosition $position
     * @return GovernancePosition
     */
    public function reactivate(GovernancePosition $position): GovernancePosition
    {
        if ($position->is_active === true) {
            return $position;
        }

        return DB::transaction(function () use ($position): GovernancePosition {
            $before = $position->getOriginal();
            $position->is_active = true;
            $position->save();
            $changes = $position->getChanges();

            $this->dispatchAfterCommit(new GovernancePositionUpdated($position, $before, $changes));

            return $position->refresh();
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
        // 2. code — required, uppercase, unique within the body.
        // ---------------------------------------------------------------------
        $codeValue = $attributes['code'] ?? null;

        if (!is_string($codeValue) || trim($codeValue) === '') {
            throw ValidationException::withMessages([
                'code' => ['The position code is required.'],
            ]);
        }

        $code = strtoupper(trim($codeValue));

        if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
            throw ValidationException::withMessages([
                'code' => [
                    sprintf(
                        'The position code must not exceed %d characters.',
                        self::MAX_CODE_LENGTH,
                    ),
                ],
            ]);
        }

        if ($this->repository->existsByCode($tenantId, $bodyId, $code)) {
            throw ValidationException::withMessages([
                'code' => [
                    sprintf(
                        'A position with the code "%s" already exists in this body.',
                        $code,
                    ),
                ],
            ]);
        }

        $attributes['code'] = $code;

        // ---------------------------------------------------------------------
        // 3. Names — at least one must be non-empty.
        // ---------------------------------------------------------------------
        $this->assertAtLeastOneName($attributes);

        // ---------------------------------------------------------------------
        // 4. sort_order — non-negative integer within range.
        // ---------------------------------------------------------------------
        $attributes['sort_order'] = $this->normaliseSortOrder($attributes['sort_order'] ?? null);

        // ---------------------------------------------------------------------
        // 5. Boolean flags — cast to bool.
        // ---------------------------------------------------------------------
        $attributes['is_voting']  = (bool) ($attributes['is_voting'] ?? false);
        $attributes['is_officer'] = (bool) ($attributes['is_officer'] ?? false);
        $attributes['is_active']  = (bool) ($attributes['is_active'] ?? true);

        return $attributes;
    }

    // =========================================================================
    // Private — normalisation and validation (update)
    // =========================================================================

    /**
     * Normalise and validate the input for an `update()` call.
     *
     * @param  GovernancePosition   $position
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normaliseForUpdate(GovernancePosition $position, array $data): array
    {
        $attributes = $this->whitelist($data);

        // ---------------------------------------------------------------------
        // 1. governance_body_id — immutable.
        // ---------------------------------------------------------------------
        if (array_key_exists('governance_body_id', $attributes)) {
            $proposed = $attributes['governance_body_id'];

            if ((int) $proposed !== (int) $position->governance_body_id) {
                throw ValidationException::withMessages([
                    'governance_body_id' => [
                        'A position cannot be moved to a different governance body. '
                        . 'Create a new position on the target body instead.',
                    ],
                ]);
            }

            unset($attributes['governance_body_id']);
        }

        // ---------------------------------------------------------------------
        // 2. code — when set, must remain unique within the body
        //    (excluding this row).
        // ---------------------------------------------------------------------
        if (array_key_exists('code', $attributes)) {
            $codeValue = $attributes['code'];

            if (!is_string($codeValue) || trim($codeValue) === '') {
                throw ValidationException::withMessages([
                    'code' => ['The position code cannot be empty.'],
                ]);
            }

            $code = strtoupper(trim($codeValue));

            if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
                throw ValidationException::withMessages([
                    'code' => [
                        sprintf(
                            'The position code must not exceed %d characters.',
                            self::MAX_CODE_LENGTH,
                        ),
                    ],
                ]);
            }

            if ($this->repository->existsByCode(
                $position->tenant_id,
                $position->governance_body_id,
                $code,
                (int) $position->id,
            )) {
                throw ValidationException::withMessages([
                    'code' => [
                        sprintf(
                            'A position with the code "%s" already exists in this body.',
                            $code,
                        ),
                    ],
                ]);
            }

            $attributes['code'] = $code;
        }

        // ---------------------------------------------------------------------
        // 3. Names — the POST-update state must have at least one non-empty
        //    name.
        // ---------------------------------------------------------------------
        $this->assertAtLeastOneNameAfterUpdate($position, $attributes);

        // ---------------------------------------------------------------------
        // 4. sort_order — when set, must be non-negative integer within range.
        // ---------------------------------------------------------------------
        if (array_key_exists('sort_order', $attributes)) {
            $attributes['sort_order'] = $this->normaliseSortOrder($attributes['sort_order']);
        }

        // ---------------------------------------------------------------------
        // 5. Boolean flags — cast to bool when present.
        // ---------------------------------------------------------------------
        if (array_key_exists('is_voting', $attributes)) {
            $attributes['is_voting'] = (bool) $attributes['is_voting'];
        }
        if (array_key_exists('is_officer', $attributes)) {
            $attributes['is_officer'] = (bool) $attributes['is_officer'];
        }
        if (array_key_exists('is_active', $attributes)) {
            $attributes['is_active'] = (bool) $attributes['is_active'];
        }

        return $attributes;
    }

    // =========================================================================
    // Private — invariant assertions
    // =========================================================================

    /**
     * Assert that at least one of `name_am` / `name_en` is non-empty
     * in the supplied attribute array.
     *
     * @param  array<string, mixed> $attributes
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAtLeastOneName(array $attributes): void
    {
        $nameAm = $attributes['name_am'] ?? null;
        $nameEn = $attributes['name_en'] ?? null;

        $hasAm = is_string($nameAm) && trim($nameAm) !== '';
        $hasEn = is_string($nameEn) && trim($nameEn) !== '';

        if (!$hasAm && !$hasEn) {
            throw ValidationException::withMessages([
                'name_am' => [
                    'At least one of name_am or name_en must be provided.',
                ],
            ]);
        }
    }

    /**
     * Assert that the POST-update state has at least one non-empty name.
     *
     * @param  GovernancePosition   $position
     * @param  array<string, mixed> $attributes
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAtLeastOneNameAfterUpdate(GovernancePosition $position, array $attributes): void
    {
        $proposedAm = array_key_exists('name_am', $attributes)
            ? $attributes['name_am']
            : $position->name_am;

        $proposedEn = array_key_exists('name_en', $attributes)
            ? $attributes['name_en']
            : $position->name_en;

        $hasAm = is_string($proposedAm) && trim($proposedAm) !== '';
        $hasEn = is_string($proposedEn) && trim($proposedEn) !== '';

        if (!$hasAm && !$hasEn) {
            throw ValidationException::withMessages([
                'name_am' => [
                    'At least one of name_am or name_en must remain non-empty.',
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
     * Normalise a `sort_order` value.
     *
     * Accepts a null (defaults to 0), an integer, or a numeric string.
     * Rejects anything else, and rejects values outside the
     * `unsignedSmallInteger` range.
     *
     * @param  mixed $value
     * @return int
     *
     * @throws ValidationException
     */
    private function normaliseSortOrder(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (!is_numeric($value)) {
            throw ValidationException::withMessages([
                'sort_order' => ['The sort order must be an integer.'],
            ]);
        }

        $intValue = (int) $value;

        if ($intValue < 0 || $intValue > self::MAX_SORT_ORDER) {
            throw ValidationException::withMessages([
                'sort_order' => [
                    sprintf(
                        'The sort order must be between 0 and %d.',
                        self::MAX_SORT_ORDER,
                    ),
                ],
            ]);
        }

        return $intValue;
    }

    /**
     * Determine whether a governance body exists in a tenant.
     *
     * Uses a direct model query rather than the
     * `GovernanceBodyRepository` because the position service does
     * not inject that repository — a deliberate choice to keep the
     * two services independent. The check is a single primary-key
     * lookup on an indexed column, which is efficient regardless of
     * which layer issues it.
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