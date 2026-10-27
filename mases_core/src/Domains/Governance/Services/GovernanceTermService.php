<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 16 of 27
 * =============================================================================
 *
 * File:            Services/GovernanceTermService.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (service)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceTerm` model (G2b file 14 of
 *   27) could be written to directly by any caller with access to
 *   `GovernanceTerm::create()` or `$term->save()`, but the domain had
 *   no single enforcement point for its invariants. Nothing stopped a
 *   caller from persisting a term with a foreign body id that does not
 *   exist, a body in a different tenant, an inverted effective-date
 *   range, or a term with no bilingual label. Nothing dispatched a
 *   domain event after a successful write, so the audit trail could
 *   not record term changes.
 *
 * After:
 *   One file that declares the domain's single write path for
 *   `GovernanceTerm`. Every mutation — registration, update,
 *   deactivation, reactivation — flows through this service. The
 *   service:
 *
 *     1. Whitelists the caller's input against the model's fillable
 *        set, dropping any key the model does not accept.
 *
 *     2. Validates every domain invariant that must hold before a
 *        row is persisted:
 *
 *          · `governance_body_id` — required on register; must
 *            reference a body in the same tenant. IMMUTABLE after
 *            registration — a term is defined by its body and cannot
 *            be moved to another body. (A request to change it is
 *            rejected with a structured error.)
 *
 *          · `effective_from` / `effective_until` — when both are
 *            set, `from <= until`. Each, individually, is nullable.
 *
 *          · At least one of `label_am` / `label_en` must be
 *            non-empty after registration, and must remain
 *            non-empty after any update.
 *
 *          · `is_active` — cast to boolean; no further validation.
 *
 *     3. Wraps every write in a database transaction.
 *
 *     4. Dispatches the appropriate domain event after the
 *        transaction commits, gated on
 *        `config('governance.audit.emit_events')`:
 *
 *          · `GovernanceTermRegistered` — on `register()`.
 *          · `GovernanceTermUpdated`    — on `update()`,
 *                                          `deactivate()`, and
 *                                          `reactivate()`.
 *
 *     5. Returns the persisted model, refreshed from the database.
 *
 *   What the service deliberately does NOT do:
 *
 *     · It does NOT perform authorization. The controller (G2b file
 *       21) invokes the policy (G2b file 17) before calling this
 *       service.
 *
 *     · It does NOT enforce non-overlap. Two terms of the same body
 *       may overlap in date range. This is a deliberate omission —
 *       the schema permits overlap and the master prompt §11 does
 *       not require non-overlap. A future CR that decides the
 *       organization's policy requires non-overlap would add a
 *       service-layer check; the schema remains permissive.
 *
 *     · It does NOT auto-populate labels from dates. The label is a
 *       human-entered string; the model's `display_label` accessor
 *       (G2b file 14) synthesises a date-range fallback at read time
 *       if neither label is set, but the service requires at least
 *       one non-empty label at write time.
 *
 *     · It does NOT enforce label uniqueness. Two terms of the same
 *       body may share a label (unusual but not forbidden).
 *
 *     · It does NOT soft-delete. There is no `delete()` method.
 *       Terms are deactivated (`is_active = false`), not removed.
 *
 *     · It does NOT write to any other table. Every table reference
 *       is a read (existence check against `governance_bodies`).
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
 *          Once registered, a term's `governance_body_id` never
 *          changes.
 *
 *   INV-3  Effective-date ordering.
 *          When both `effective_from` and `effective_until` are
 *          non-null, `effective_from <= effective_until`.
 *
 *   INV-4  Label presence.
 *          Every persisted row has at least one non-empty label
 *          (`label_am` or `label_en`).
 *
 *   INV-5  Tenant alignment.
 *          Every persisted row's `tenant_id` matches the resolved
 *          tenant.
 *
 * Any write that would violate one of these invariants throws a
 * `ValidationException` before the transaction commits.
 *
 * =============================================================================
 * Why a service, not a trait on the model
 * =============================================================================
 * The same reasoning that governs `GovernanceBodyService` (G2a file 5
 * of 18) and `GovernancePositionService` (G2b file 4 of 27): domain
 * invariants require database reads (existence checks) that a static
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
 * =============================================================================
 * Forward references — GovernanceTermRegistered and
 * GovernanceTermUpdated
 * =============================================================================
 * This service references two event classes delivered later in this
 * unit:
 *
 *     G2b file 22:  Events/GovernanceTermRegistered.php
 *     G2b file 23:  Events/GovernanceTermUpdated.php
 *
 * The class files are NOT loaded when this service is uploaded —
 * PHP resolves `use` statements lazily. The service class loads fine;
 * the event classes are only instantiated when a write method runs
 * its after-commit closure.
 *
 * A live caller must not invoke `register()`, `update()`,
 * `deactivate()`, or `reactivate()` until files 22 and 23 are on
 * disk. The G2b deployment sequence delivers the routes amendment
 * (file 27) last, so no live HTTP caller reaches these methods
 * during the deployment window.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use DateTimeInterface;
use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceTermRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceTermUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceTermRepository;

/**
 * GovernanceTermService
 *
 * The single write path for governance terms. Every mutation of a
 * `GovernanceTerm` row goes through this service.
 *
 * See the file-level change-verification block for the service's
 * responsibilities, its invariants, its event-dispatch pattern, and
 * its deliberate omissions (no overlap enforcement, no auto-labels,
 * no label uniqueness).
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
 * @version 1.0.0
 */
final class GovernanceTermService
{
    /**
     * Columns that may be supplied to `register()` and `update()`.
     *
     * This is the model's `$fillable` array minus `tenant_id`, which
     * is supplied explicitly to `register()` as a separate argument
     * and is never updated.
     *
     * @var list<string>
     */
    private const ALLOWED_FIELDS = [
        'governance_body_id',
        'label_am',
        'label_en',
        'effective_from',
        'effective_until',
        'notes',
        'is_active',
    ];

    /**
     * Construct the service with its repository dependency.
     *
     * @param GovernanceTermRepository $repository
     */
    public function __construct(
        private readonly GovernanceTermRepository $repository,
    ) {
    }

    // =========================================================================
    // Public API — writes
    // =========================================================================

    /**
     * Register a new governance term.
     *
     * Validates every domain invariant (INV-1 through INV-5) before
     * persisting. The entire operation runs in a single database
     * transaction; the `GovernanceTermRegistered` event fires only
     * after commit.
     *
     * @param  array<string, mixed> $data
     * @param  int                  $tenantId
     * @return GovernanceTerm
     *
     * @throws ValidationException
     */
    public function register(array $data, int $tenantId): GovernanceTerm
    {
        $attributes = $this->normaliseForRegister($data, $tenantId);

        return DB::transaction(function () use ($attributes, $tenantId): GovernanceTerm {
            $bodyId = (int) $attributes['governance_body_id'];
            $this->lockBodyForTermWrite($tenantId, $bodyId);
            if ((bool) ($attributes['is_active'] ?? true)) {
                $this->assertNoOverlappingActiveTerm(
                    bodyId: $bodyId,
                    startDate: $attributes['effective_from'] ?? null,
                    endDate: $attributes['effective_until'] ?? null,
                    exceptId: null,
                );
            }

            $term = GovernanceTerm::create([
                'tenant_id' => $tenantId,
                ...$attributes,
            ]);

            $this->dispatchAfterCommit(new GovernanceTermRegistered($term));

            return $term;
        });
    }

    /**
     * Update an existing governance term.
     *
     * The `governance_body_id` is immutable; passing a value that
     * differs from the current one throws a ValidationException
     * (INV-2). Every other whitelisted field may be updated.
     *
     * @param  GovernanceTerm       $term
     * @param  array<string, mixed> $data
     * @return GovernanceTerm
     *
     * @throws ValidationException
     */
    public function update(GovernanceTerm $term, array $data): GovernanceTerm
    {
        $attributes = $this->normaliseForUpdate($term, $data);

        if ($attributes === []) {
            return $term;
        }

        return DB::transaction(function () use ($term, $attributes): GovernanceTerm {
            $bodyId = (int) $term->governance_body_id;
            $before = $term->getOriginal();
            if ((bool) ($attributes['is_active'] ?? $term->is_active)
                && (array_key_exists('effective_from', $attributes) || array_key_exists('effective_until', $attributes))) {
                $bodyId = (int) $term->governance_body_id;
                $this->lockBodyForTermWrite((int) $term->tenant_id, $bodyId);
                $this->assertNoOverlappingActiveTerm(
                    bodyId: $bodyId,
                    startDate: array_key_exists('effective_from', $attributes) ? $attributes['effective_from'] : $term->effective_from,
                    endDate: array_key_exists('effective_until', $attributes) ? $attributes['effective_until'] : $term->effective_until,
                    exceptId: (int) $term->id,
                );
            }
            $term->fill($attributes)->save();
            $changes = $term->getChanges();

            $this->dispatchAfterCommit(new GovernanceTermUpdated($term, $before, $changes));

            return $term->refresh();
        });
    }

    /**
     * Deactivate a governance term (set `is_active = false`).
     *
     * Idempotent: deactivating an already-deactivated term is a no-op
     * that does not dispatch an event.
     *
     * @param  GovernanceTerm $term
     * @return GovernanceTerm
     */
    public function deactivate(GovernanceTerm $term): GovernanceTerm
    {
        if ($term->is_active === false) {
            return $term;
        }

        return DB::transaction(function () use ($term): GovernanceTerm {
            $before = $term->getOriginal();
            $term->is_active = false;
            $term->save();
            $changes = $term->getChanges();

            $this->dispatchAfterCommit(new GovernanceTermUpdated($term, $before, $changes));

            return $term->refresh();
        });
    }

    /**
     * Reactivate a previously deactivated governance term.
     *
     * Idempotent: reactivating an already-active term is a no-op that
     * does not dispatch an event.
     *
     * @param  GovernanceTerm $term
     * @return GovernanceTerm
     */
    public function reactivate(GovernanceTerm $term): GovernanceTerm
    {
        if ($term->is_active === true) {
            return $term;
        }

        return DB::transaction(function () use ($term): GovernanceTerm {
            $bodyId = (int) $term->governance_body_id;
            $this->lockBodyForTermWrite((int) $term->tenant_id, $bodyId);
            $this->assertNoOverlappingActiveTerm(
                bodyId: $bodyId,
                startDate: $term->effective_from,
                endDate: $term->effective_until,
                exceptId: (int) $term->id,
            );

            $before = $term->getOriginal();
            $term->is_active = true;
            $term->save();
            $changes = $term->getChanges();

            $this->dispatchAfterCommit(new GovernanceTermUpdated($term, $before, $changes));

            return $term->refresh();
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
        // 2. Labels — at least one must be non-empty.
        // ---------------------------------------------------------------------
        $this->assertAtLeastOneLabel($attributes);

        // ---------------------------------------------------------------------
        // 3. Effective-date range — from <= until when both are set.
        // ---------------------------------------------------------------------
        $this->assertEffectiveDateOrdering(
            $attributes['effective_from'] ?? null,
            $attributes['effective_until'] ?? null,
        );

        // ---------------------------------------------------------------------
        // 4. is_active — default true.
        // ---------------------------------------------------------------------
        if (!array_key_exists('is_active', $attributes) || $attributes['is_active'] === null) {
            $attributes['is_active'] = true;
        } else {
            $attributes['is_active'] = (bool) $attributes['is_active'];
        }

        return $attributes;
    }

    // =========================================================================
    // Private — normalisation and validation (update)
    // =========================================================================

    /**
     * Normalise and validate the input for an `update()` call.
     *
     * @param  GovernanceTerm       $term
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normaliseForUpdate(GovernanceTerm $term, array $data): array
    {
        $attributes = $this->whitelist($data);

        // ---------------------------------------------------------------------
        // 1. governance_body_id — immutable.
        // ---------------------------------------------------------------------
        if (array_key_exists('governance_body_id', $attributes)) {
            $proposed = $attributes['governance_body_id'];

            if ((int) $proposed !== (int) $term->governance_body_id) {
                throw ValidationException::withMessages([
                    'governance_body_id' => [
                        'A term cannot be moved to a different governance body. '
                        . 'Create a new term on the target body instead.',
                    ],
                ]);
            }

            unset($attributes['governance_body_id']);
        }

        // ---------------------------------------------------------------------
        // 2. Labels — the POST-update state must have at least one
        //    non-empty label.
        // ---------------------------------------------------------------------
        $this->assertAtLeastOneLabelAfterUpdate($term, $attributes);

        // ---------------------------------------------------------------------
        // 3. Effective-date range — from <= until when both are set
        //    after the update.
        // ---------------------------------------------------------------------
        $from = array_key_exists('effective_from', $attributes)
            ? $attributes['effective_from']
            : $term->effective_from;

        $until = array_key_exists('effective_until', $attributes)
            ? $attributes['effective_until']
            : $term->effective_until;

        $this->assertEffectiveDateOrdering($from, $until);

        // ---------------------------------------------------------------------
        // 4. is_active — cast to bool when present.
        // ---------------------------------------------------------------------
        if (array_key_exists('is_active', $attributes)) {
            $attributes['is_active'] = (bool) $attributes['is_active'];
        }

        return $attributes;
    }

    // =========================================================================
    // Private — invariant assertions
    // =========================================================================

    /**
     * Assert that at least one of `label_am` / `label_en` is non-empty
     * in the supplied attribute array.
     *
     * @param  array<string, mixed> $attributes
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAtLeastOneLabel(array $attributes): void
    {
        $labelAm = $attributes['label_am'] ?? null;
        $labelEn = $attributes['label_en'] ?? null;

        $hasAm = is_string($labelAm) && trim($labelAm) !== '';
        $hasEn = is_string($labelEn) && trim($labelEn) !== '';

        if (!$hasAm && !$hasEn) {
            throw ValidationException::withMessages([
                'label_am' => [
                    'At least one of label_am or label_en must be provided.',
                ],
            ]);
        }
    }

    /**
     * Assert that the POST-update state has at least one non-empty
     * label.
     *
     * @param  GovernanceTerm       $term
     * @param  array<string, mixed> $attributes
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAtLeastOneLabelAfterUpdate(GovernanceTerm $term, array $attributes): void
    {
        $proposedAm = array_key_exists('label_am', $attributes)
            ? $attributes['label_am']
            : $term->label_am;

        $proposedEn = array_key_exists('label_en', $attributes)
            ? $attributes['label_en']
            : $term->label_en;

        $hasAm = is_string($proposedAm) && trim($proposedAm) !== '';
        $hasEn = is_string($proposedEn) && trim($proposedEn) !== '';

        if (!$hasAm && !$hasEn) {
            throw ValidationException::withMessages([
                'label_am' => [
                    'At least one of label_am or label_en must remain non-empty.',
                ],
            ]);
        }
    }

    /**
     * Serialize term writes by locking the owning body row. This makes the
     * overlap check safe across independent MySQL connections; a query-only
     * check without this shared lock permits two concurrent inserts to race.
     */
    private function lockBodyForTermWrite(int $tenantId, int $bodyId): void
    {
        GovernanceBody::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($bodyId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Reject inclusive date-range overlap among active terms of one body. */
    private function assertNoOverlappingActiveTerm(
        int $bodyId,
        mixed $startDate,
        mixed $endDate,
        ?int $exceptId,
    ): void {
        $query = GovernanceTerm::query()
            ->where('governance_body_id', $bodyId)
            ->where('is_active', true);

        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        if ($startDate !== null) {
            $query->where(function ($range) use ($startDate): void {
                $range->whereNull('effective_until')->orWhere('effective_until', '>=', $startDate);
            });
        }
        if ($endDate !== null) {
            $query->where(function ($range) use ($endDate): void {
                $range->whereNull('effective_from')->orWhere('effective_from', '<=', $endDate);
            });
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => ['An active governance term for this body already overlaps the supplied date range.'],
            ]);
        }
    }

    /**
     * Assert that `effective_from <= effective_until` when both are
     * set.
     *
     * Accepts either a date string (`Y-m-d`), a `Carbon` instance,
     * a `DateTimeInterface`, or null. Null on either side means the
     * range is open-ended in that direction, which is always valid.
     *
     * @param  mixed $from
     * @param  mixed $until
     * @return void
     *
     * @throws ValidationException
     */

    private function assertEffectiveDateOrdering(mixed $from, mixed $until): void
    {
        if ($from === null || $until === null) {
            return;
        }

        $fromDate  = $this->toCarbon($from);
        $untilDate = $this->toCarbon($until);

        if ($fromDate === null || $untilDate === null) {
            // Unparseable input — the request class catches this at
            // the HTTP boundary. The service does not attempt a
            // second parse; it treats unparseable input as "not
            // enough information to compare".
            return;
        }

        if ($fromDate->greaterThan($untilDate)) {
            throw ValidationException::withMessages([
                'effective_until' => [
                    'The effective-until date must be on or after the effective-from date.',
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
     * `GovernanceBodyRepository` because the term service does not
     * inject that repository — a deliberate choice to keep the
     * services independent. The check is a single primary-key
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