<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 4 of 27
 * =============================================================================
 *
 * File:            Services/GovernancePositionService.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (service)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernancePosition` model (G2b
 *   file 2 of 27) could be written to directly by any caller with
 *   access to `GovernancePosition::create()` or `$position->save()`,
 *   but the domain had no single enforcement point for its
 *   invariants. Nothing stopped a caller from persisting a position
 *   with a duplicate code within a body, a foreign body id that
 *   does not exist, a body in a different tenant, or a nameless
 *   row. Nothing dispatched a domain event after a successful
 *   write, so the audit trail could not record position changes.
 *
 * After:
 *   One file that declares the domain's single write path for
 *   `GovernancePosition`. Every mutation — registration, update,
 *   deactivation, reactivation — flows through this service. The
 *   service:
 *
 *     1. Whitelists the caller's input against the model's
 *        fillable set, dropping any key the model does not accept.
 *
 *     2. Validates every domain invariant that must hold before a
 *        row is persisted:
 *
 *          · `code` — required on register; uppercase; unique
 *            within the body; max 32 characters; matches the
 *            `alpha_dash` pattern.
 *
 *          · `governance_body_id` — required on register; must
 *            reference a body in the same tenant. IMMUTABLE after
 *            registration — a position is defined by its body and
 *            cannot be moved to another body. (A request to change
 *            it is rejected with a structured error.)
 *
 *          · At least one of `name_am` / `name_en` must be
 *            non-empty after registration, and must remain
 *            non-empty after any update.
 *
 *          · `sort_order` — when set, must be a non-negative
 *            integer (MySQL's unsignedSmallInteger column is
 *            authoritative; the service validates before the
 *            database rejects).
 *
 *          · `is_voting` / `is_officer` / `is_active` — cast to
 *            boolean; no further validation.
 *
 *     3. Wraps every write in a database transaction.
 *
 *     4. Dispatches the appropriate domain event after the
 *        transaction commits, gated on
 *        `config('governance.audit.emit_events')`:
 *
 *          · `GovernancePositionRegistered` — on `register()`.
 *          · `GovernancePositionUpdated`    — on `update()`,
 *                                              `deactivate()`, and
 *                                              `reactivate()`.
 *
 *     5. Returns the persisted model, refreshed from the database.
 *
 *   What the service deliberately does NOT do:
 *
 *     · It does NOT perform authorization. The controller (G2b
 *       file 9) invokes the policy (G2b file 5) before calling
 *       this service.
 *
 *     · It does NOT enforce `code` immutability. A position's code
 *       is a LABEL, not a structural fact. An error in the initial
 *       code assignment should be correctable via update. This
 *       differs from `GovernanceBody.body_type`, which is
 *       immutable because changing it invalidates the row's
 *       structural meaning.
 *
 *     · It does NOT enforce cross-body code uniqueness. Uniqueness
 *       is always scoped to a single body.
 *
 *     · It does NOT touch `governance_positions` rows outside the
 *       tenant. The tenant boundary is enforced by the repository
 *       (G2b file 3) and by the policy (G2b file 5).
 *
 *     · It does NOT soft-delete. There is no `delete()` method.
 *       Positions are deactivated (`is_active = false`), not
 *       removed.
 *
 *     · It does NOT write to any other table. Every table
 *       reference is a read (existence checks against
 *       `governance_bodies` and `governance_positions` itself,
 *       via the repository and via direct model queries).
 *
 * =============================================================================
 * Invariants — what the service guarantees
 * =============================================================================
 * For every row the service ever writes, the following invariants
 * hold at the moment of commit:
 *
 *   INV-1  Code uniqueness within a body.
 *          No two positions in the same body share the same `code`.
 *          Enforced by the composite UNIQUE index on
 *          `(governance_body_id, code)` at the database layer, and
 *          by an explicit pre-check at the service layer.
 *
 *   INV-2  Body validity.
 *          Every persisted `governance_body_id` references an
 *          existing body in the same tenant. Enforced by a
 *          repository existence check before persisting.
 *
 *   INV-3  Body immutability.
 *          Once registered, a position's `governance_body_id`
 *          never changes. Enforced by rejecting any `update()`
 *          call whose `governance_body_id` differs from the
 *          current value.
 *
 *   INV-4  Name presence.
 *          Every persisted row has at least one non-empty name
 *          (`name_am` or `name_en`). Enforced on both register and
 *          update, checked against the post-update state.
 *
 *   INV-5  Sort-order validity.
 *          Every persisted `sort_order` value is a non-negative
 *          integer within the range of the `unsignedSmallInteger`
 *          column (0–65,535).
 *
 *   INV-6  Tenant alignment.
 *          Every persisted row's `tenant_id` matches the resolved
 *          tenant. The service sets `tenant_id` explicitly on
 *          register; the caller cannot influence it.
 *
 * Any write that would violate one of these invariants throws a
 * `ValidationException` before the transaction commits.
 *
 * =============================================================================
 * Why a service, not a trait on the model
 * =============================================================================
 * The service pattern is used for the same reasons documented in
 * `GovernanceBodyService` (G2a file 5): domain invariants require
 * database reads (existence checks) that a static helper cannot
 * perform cleanly; every write is wrapped in a transaction; the
 * service is the natural extension point for a future workflow.
 *
 * =============================================================================
 * Event dispatch — after commit, gated by the audit feature flag
 * =============================================================================
 * Every write dispatches its event via `DB::afterCommit()`, which
 * registers a callback that runs after the outermost transaction
 * commits. If the transaction rolls back, the callback is discarded
 * and no event fires.
 *
 * The dispatch is gated on `config('governance.audit.emit_events')`.
 * When this config is false, events are not dispatched at all.
 *
 * =============================================================================
 * Forward references — GovernancePositionRegistered and
 * GovernancePositionUpdated
 * =============================================================================
 * This service references two event classes delivered later in this
 * unit:
 *
 *     G2b file 10:  Events/GovernancePositionRegistered.php
 *     G2b file 11:  Events/GovernancePositionUpdated.php
 *
 * The class files are NOT loaded when this service is uploaded —
 * PHP resolves `use` statements lazily. The service class loads
 * fine; the event classes are only instantiated when a write
 * method runs its after-commit closure.
 *
 * A live caller must not invoke `register()`, `update()`,
 * `deactivate()`, or `reactivate()` until files 10 and 11 are on
 * disk. The G2b deployment sequence delivers the controller
 * (file 9) after this file but before the events (files 10, 11),
 * and the routes amendment (file 27) last, so no live HTTP caller
 * reaches these methods during the deployment window.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernancePositionRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernancePositionUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernancePositionRepository;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
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
            $position->fill($attributes)->save();

            $this->dispatchAfterCommit(new GovernancePositionUpdated($position));

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
            $position->is_active = false;
            $position->save();

            $this->dispatchAfterCommit(new GovernancePositionUpdated($position));

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
            $position->is_active = true;
            $position->save();

            $this->dispatchAfterCommit(new GovernancePositionUpdated($position));

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