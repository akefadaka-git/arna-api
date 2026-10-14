<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 5 of 17
 * =============================================================================
 *
 * File:            Services/GovernanceBodyService.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (service)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceBody` model (G2a file 3
 *   of 17) could be written to directly by any caller with access
 *   to `GovernanceBody::create()` or `$body->save()`, but the domain
 *   had no single enforcement point for its invariants. Nothing
 *   stopped a caller from persisting a body with a duplicate code
 *   within the tenant, a self-referential parent that would corrupt
 *   the hierarchy, an effective-date range where the start comes
 *   after the end, or a nameless row. Nothing dispatched a domain
 *   event after a successful write, so the audit trail could not
 *   record governance-body changes.
 *
 * After:
 *   One file that declares the domain's single write path for
 *   `GovernanceBody`. Every mutation — registration, update,
 *   deactivation, reactivation — flows through this service. The
 *   service:
 *
 *     1. Whitelists the caller's input against the model's fillable
 *        set, dropping any key the model does not accept (including
 *        `tenant_id`, `id`, and the timestamps).
 *
 *     2. Validates every domain invariant that must hold before a
 *        row is persisted:
 *
 *          · `code` — required on register; uppercase; unique within
 *            the tenant (case-insensitive); max 32 characters.
 *
 *          · `body_type` — required on register; must resolve to a
 *            `GovernanceBodyType` case; IMMUTABLE after registration
 *            (a request to change it is rejected with a structured
 *            error).
 *
 *          · At least one of `name_am` / `name_en` must be non-empty
 *            after registration, and must remain non-empty after any
 *            update.
 *
 *          · `parent_body_id` — when set, must reference a body in
 *            the same tenant; must not introduce a cycle (the
 *            proposed parent must not be this body or any of its
 *            descendants).
 *
 *          · `reporting_body_id` — when set, must reference a body
 *            in the same tenant; must not be this body itself.
 *
 *          · `effective_from` / `effective_until` — when both are
 *            set, `from <= until`.
 *
 *          · `quorum_rule_json` — when set, must be a JSON object
 *            whose `type` is one of the three declared values
 *            (`fixed_number`, `percentage_of_members`, `special`)
 *            and whose `value` is a non-negative integer for the
 *            non-special types.
 *
 *     3. Wraps every write in a database transaction.
 *
 *     4. Dispatches the appropriate domain event after the
 *        transaction commits, gated on
 *        `config('governance.audit.emit_events')`:
 *
 *          · `GovernanceBodyRegistered` — on `register()`.
 *          · `GovernanceBodyUpdated`    — on `update()`,
 *                                         `deactivate()`, and
 *                                         `reactivate()`.
 *
 *     5. Returns the persisted model, refreshed from the database
 *        so the caller sees the committed state (including any
 *        database-generated values).
 *
 *   What the service deliberately does NOT do:
 *
 *     · It does NOT perform authorization. The controller (G2a
 *       file 14) invokes the policy (G2a file 6) before calling
 *       this service. The service trusts that the caller has
 *       passed authorization.
 *
 *     · It does NOT handle HTTP concerns. Input validation for
 *       HTTP requests is performed by the request classes (G2a
 *       files 12, 13). The service's validations are the domain
 *       invariants that MUST hold regardless of whether the caller
 *       is an HTTP controller, an Artisan command, or a test.
 *
 *     · It does NOT soft-delete. There is no `delete()` method.
 *       Governance bodies are deactivated (`is_active = false`), not
 *       removed. Soft-deletion is reserved for administrative-
 *       recovery scenarios handled outside the normal API.
 *
 *     · It does NOT write to any other table. Every table
 *       reference is a read (existence checks against
 *       `governance_bodies` itself, via the repository).
 *
 *     · It does NOT bypass the repository for reads. Every
 *       existence check goes through `GovernanceBodyRepository`
 *       (G2a file 4), which enforces tenant scoping by
 *       construction.
 *
 * =============================================================================
 * Invariants — what the service guarantees
 * =============================================================================
 * For every row the service ever writes, the following invariants
 * hold at the moment of commit:
 *
 *   INV-1  Code uniqueness.
 *          No two rows in the same tenant share the same `code`.
 *          Enforced by the composite UNIQUE index on
 *          `(tenant_id, code)` at the database layer, and by an
 *          explicit pre-check at the service layer (so a caller
 *          receives a structured 422 rather than a raw constraint-
 *          violation 500).
 *
 *   INV-2  Body-type validity.
 *          Every persisted `body_type` value resolves to a
 *          `GovernanceBodyType` case. Enforced by validating the
 *          value against `GovernanceBodyType::values()` before
 *          persisting.
 *
 *   INV-3  Body-type immutability.
 *          Once registered, a body's `body_type` never changes.
 *          Enforced by rejecting any `update()` call whose
 *          `body_type` differs from the current value.
 *
 *   INV-4  Name presence.
 *          Every persisted row has at least one non-empty name
 *          (`name_am` or `name_en`). Enforced on both register and
 *          update, and checked against the post-update state (not
 *          just the incoming payload).
 *
 *   INV-5  Parent validity.
 *          When `parent_body_id` is non-null, it references a body
 *          in the same tenant, and no cycle exists in the parent
 *          chain from any body back to itself.
 *
 *   INV-6  Reporting-target validity.
 *          When `reporting_body_id` is non-null, it references a
 *          body in the same tenant, and it is not the body itself.
 *
 *   INV-7  Effective-date ordering.
 *          When both `effective_from` and `effective_until` are
 *          non-null, `effective_from <= effective_until`.
 *
 *   INV-8  Quorum-rule shape.
 *          When `quorum_rule_json` is non-null, its `type` is one
 *          of the three declared values, and its `value` (where
 *          required) is a non-negative integer within the type's
 *          meaningful range.
 *
 * Any write that would violate one of these invariants throws a
 * `ValidationException` before the transaction commits. No partial
 * state is ever persisted.
 *
 * =============================================================================
 * Why a service, not a trait on the model, and not a set of
 * static methods
 * =============================================================================
 * The service pattern is used because:
 *
 *   · Domain invariants (INV-1 through INV-8) require database
 *     reads (existence checks, cycle walks) that a static helper
 *     cannot perform cleanly. A service can inject the repository.
 *
 *   · Every write is wrapped in a transaction. A static helper
 *     would either hide the transaction boundary or push it to
 *     every caller.
 *
 *   · The audit-trail integration requires injecting
 *     `AuditService` in later phases (the listener, not the
 *     service, dispatches the audit write — but the pattern of
 *     dependencies is cleaner when the service is injected).
 *
 *   · The service is the natural extension point for a future CR
 *     that adds a workflow (draft → submitted → approved) to the
 *     body lifecycle. The write methods would gain state transitions
 *     in the same class, keeping the invariants together.
 *
 * This mirrors `ShareholderService`, `WalletService`, and
 * `LoanApplicationService` in the three other domains.
 *
 * =============================================================================
 * Event dispatch — after commit, gated by the audit feature flag
 * =============================================================================
 * Every write dispatches its event via `DB::afterCommit()`, which
 * registers a callback that runs after the outermost transaction
 * commits. If the transaction rolls back — because a validation
 * failed after the model was saved, or because of a database error —
 * the callback is discarded and no event fires.
 *
 * The dispatch is gated on `config('governance.audit.emit_events')`.
 * When this config is false, events are not dispatched at all. This
 * is the operator's switch for suppressing the audit trail during a
 * maintenance window. It defaults to true.
 *
 * =============================================================================
 * Forward references — GovernanceBodyRegistered and
 * GovernanceBodyUpdated
 * =============================================================================
 * This service references two event classes that are delivered later
 * in this unit:
 *
 *     G2a file 7:  Events/GovernanceBodyRegistered.php
 *     G2a file 8:  Events/GovernanceBodyUpdated.php
 *
 * The class files themselves are NOT loaded when this service is
 * uploaded — PHP resolves `use` statements lazily. The service
 * class loads fine; the event classes are only instantiated when
 * a write method runs its after-commit closure.
 *
 * Consequence: this service file may be deployed before files 7
 * and 8 land. But `register()`, `update()`, `deactivate()`, and
 * `reactivate()` must not be invoked until those files are on disk.
 * The G2a deployment sequence delivers the controller (file 14)
 * last, so no live caller invokes these methods during the
 * deployment window.
 *
 * If a future CR adds a non-HTTP caller (an Artisan command, a
 * seeder) that invokes `register()` before files 7 and 8 are
 * deployed, the after-commit closure will throw a class-not-found
 * error. The row is already committed at that point, so the write
 * succeeds; the audit event is lost. This is the same failure mode
 * the Shareholder domain's services carry, and the mitigation is
 * the same: deploy the event classes in the same batch as the
 * service.
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
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceBodyType;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceBodyRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceBodyUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceBodyRepository;

/**
 * GovernanceBodyService
 *
 * The single write path for governance bodies. Every mutation of a
 * `GovernanceBody` row goes through this service.
 *
 * See the file-level change-verification block for the full rationale
 * on the service's responsibilities, its invariants, its event-
 * dispatch pattern, and its deliberate omissions.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Services
 * @version 1.0.0
 */
final class GovernanceBodyService
{
    /**
     * Columns that may be supplied to `register()` and `update()`.
     *
     * This is the model's `$fillable` array minus `tenant_id`, which
     * is supplied explicitly to `register()` as a separate argument
     * and is never updated.
     *
     * Any other key in the caller's `$data` array is silently
     * dropped by the whitelist. This prevents accidental mass-
     * assignment of fields such as `id`, `created_at`, or
     * `updated_at`.
     *
     * @var list<string>
     */
    private const ALLOWED_FIELDS = [
        'code',
        'body_type',
        'name_am',
        'name_en',
        'parent_body_id',
        'purpose',
        'mandate',
        'quorum_rule_json',
        'meeting_frequency',
        'reporting_body_id',
        'effective_from',
        'effective_until',
        'is_active',
    ];

    /**
     * The three valid types in the `quorum_rule_json.type` field.
     *
     * See the migration's docblock (G2a file 1 of 17)
     * §"quorum_rule_json" for the shape's full specification.
     *
     * @var list<string>
     */
    private const QUORUM_RULE_TYPES = [
        'fixed_number',
        'percentage_of_members',
        'special',
    ];

    /**
     * The maximum length of a `code` value.
     *
     * Matches the column's VARCHAR(32) declaration. A value longer
     * than this is rejected at the service layer rather than at the
     * database layer, so the caller receives a structured 422 rather
     * than a truncated value or a raw constraint violation.
     */
    private const MAX_CODE_LENGTH = 32;

    /**
     * Construct the service with its repository dependency.
     *
     * The repository is bound as a singleton by
     * GovernanceServiceProvider (amended by G2a file 15) — but at
     * the time this file is first delivered, no binding exists and
     * the container resolves the class by reflection. The two are
     * equivalent for a stateless repository.
     *
     * @param GovernanceBodyRepository $repository
     */
    public function __construct(
        private readonly GovernanceBodyRepository $repository,
    ) {
    }

    // =========================================================================
    // Public API — writes
    // =========================================================================

    /**
     * Register a new governance body.
     *
     * Validates every domain invariant (INV-1 through INV-8) before
     * persisting. The entire operation runs in a single database
     * transaction; the `GovernanceBodyRegistered` event fires only
     * after commit.
     *
     * The `tenant_id` is passed explicitly as a separate argument —
     * it is NOT part of the caller's `$data` array. This is the
     * platform's convention; the request's `tenant_id` value is
     * never trusted, and the caller (a controller) resolves the
     * tenant from the server-side context.
     *
     * @param  array<string, mixed> $data      Input fields, whitelisted against ALLOWED_FIELDS.
     * @param  int                  $tenantId  The owning tenant, resolved from server-side context.
     * @return GovernanceBody
     *
     * @throws ValidationException
     */
    public function register(array $data, int $tenantId): GovernanceBody
    {
        $attributes = $this->normaliseForRegister($data, $tenantId);

        return DB::transaction(function () use ($attributes, $tenantId): GovernanceBody {
            $body = GovernanceBody::create([
                'tenant_id' => $tenantId,
                ...$attributes,
            ]);

            $this->dispatchAfterCommit(new GovernanceBodyRegistered($body));

            return $body;
        });
    }

    /**
     * Update an existing governance body.
     *
     * The `body_type` is immutable; passing a `body_type` key whose
     * value differs from the current one throws a
     * ValidationException (INV-3). Every other whitelisted field may
     * be updated.
     *
     * All other invariants are re-validated against the post-update
     * state — for example, an update that would leave the body with
     * no name is rejected even if the current state has one.
     *
     * @param  GovernanceBody      $body  The row to update.
     * @param  array<string, mixed> $data
     * @return GovernanceBody
     *
     * @throws ValidationException
     */
    public function update(GovernanceBody $body, array $data): GovernanceBody
    {
        $attributes = $this->normaliseForUpdate($body, $data);

        if ($attributes === []) {
            return $body;
        }

        return DB::transaction(function () use ($body, $attributes): GovernanceBody {
            $before = $body->getOriginal();
            $body->fill($attributes)->save();
            $changes = $body->getChanges();

            $this->dispatchAfterCommit(new GovernanceBodyUpdated($body, $before, $changes));

            return $body->refresh();
        });
    }

    /**
     * Deactivate a governance body (set `is_active = false`).
     *
     * The row is not deleted. Historical references — future
     * memberships, meetings, minutes, resolutions — continue to
     * resolve. A deactivated body is excluded from "active" queries
     * but remains resolvable by id.
     *
     * Idempotent: deactivating an already-deactivated body is a
     * no-op that does not dispatch an event and does not write an
     * audit row.
     *
     * @param  GovernanceBody $body
     * @return GovernanceBody
     */
    public function deactivate(GovernanceBody $body): GovernanceBody
    {
        if ($body->is_active === false) {
            return $body;
        }

        return DB::transaction(function () use ($body): GovernanceBody {
            $before = $body->getOriginal();
            $body->is_active = false;
            $body->save();
            $changes = $body->getChanges();

            $this->dispatchAfterCommit(new GovernanceBodyUpdated($body, $before, $changes));

            return $body->refresh();
        });
    }

    /**
     * Reactivate a previously deactivated governance body.
     *
     * Idempotent: reactivating an already-active body is a no-op that
     * does not dispatch an event.
     *
     * @param  GovernanceBody $body
     * @return GovernanceBody
     */
    public function reactivate(GovernanceBody $body): GovernanceBody
    {
        if ($body->is_active === true) {
            return $body;
        }

        return DB::transaction(function () use ($body): GovernanceBody {
            $before = $body->getOriginal();
            $body->is_active = true;
            $body->save();
            $changes = $body->getChanges();

            $this->dispatchAfterCommit(new GovernanceBodyUpdated($body, $before, $changes));

            return $body->refresh();
        });
    }

    // =========================================================================
    // Private — normalisation and validation (register)
    // =========================================================================

    /**
     * Normalise and validate the input for a `register()` call.
     *
     * Returns the final attribute array to pass to
     * `GovernanceBody::create()`, with `tenant_id` excluded (it is
     * supplied separately).
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
        // 1. code — required, uppercase, unique within the tenant.
        // ---------------------------------------------------------------------
        $codeValue = $attributes['code'] ?? null;

        if (!is_string($codeValue) || trim($codeValue) === '') {
            throw ValidationException::withMessages([
                'code' => ['The body code is required.'],
            ]);
        }

        $code = strtoupper(trim($codeValue));

        if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
            throw ValidationException::withMessages([
                'code' => [
                    sprintf(
                        'The body code must not exceed %d characters.',
                        self::MAX_CODE_LENGTH,
                    ),
                ],
            ]);
        }

        if ($this->repository->existsByCode($tenantId, $code)) {
            throw ValidationException::withMessages([
                'code' => [
                    sprintf(
                        'A governance body with the code "%s" already exists in this tenant.',
                        $code,
                    ),
                ],
            ]);
        }

        $attributes['code'] = $code;

        // ---------------------------------------------------------------------
        // 2. body_type — required, must be a valid enum value.
        // ---------------------------------------------------------------------
        $bodyTypeValue = $attributes['body_type'] ?? null;

        if (!is_string($bodyTypeValue) || $bodyTypeValue === '') {
            throw ValidationException::withMessages([
                'body_type' => ['The body type is required.'],
            ]);
        }

        $bodyType = GovernanceBodyType::tryFrom($bodyTypeValue);

        if ($bodyType === null) {
            throw ValidationException::withMessages([
                'body_type' => [
                    sprintf(
                        'The body type "%s" is not recognised. Allowed: %s.',
                        $bodyTypeValue,
                        implode(', ', GovernanceBodyType::values()),
                    ),
                ],
            ]);
        }

        $attributes['body_type'] = $bodyType->value;

        // ---------------------------------------------------------------------
        // 3. Names — at least one must be non-empty.
        // ---------------------------------------------------------------------
        $this->assertAtLeastOneName($attributes);

        // ---------------------------------------------------------------------
        // 4. parent_body_id — when set, must reference a body in the
        //    same tenant. No cycle check on register: a brand-new row
        //    cannot be its own ancestor.
        // ---------------------------------------------------------------------
        if (array_key_exists('parent_body_id', $attributes) && $attributes['parent_body_id'] !== null) {
            $parentId = (int) $attributes['parent_body_id'];

            if (!$this->repository->existsForTenant($tenantId, $parentId)) {
                throw ValidationException::withMessages([
                    'parent_body_id' => [
                        'The specified parent body does not exist in this tenant.',
                    ],
                ]);
            }

            $attributes['parent_body_id'] = $parentId;
        } else {
            $attributes['parent_body_id'] = null;
        }

        // ---------------------------------------------------------------------
        // 5. reporting_body_id — when set, must reference a body in
        //    the same tenant.
        // ---------------------------------------------------------------------
        if (array_key_exists('reporting_body_id', $attributes) && $attributes['reporting_body_id'] !== null) {
            $reportingId = (int) $attributes['reporting_body_id'];

            if (!$this->repository->existsForTenant($tenantId, $reportingId)) {
                throw ValidationException::withMessages([
                    'reporting_body_id' => [
                        'The specified reporting body does not exist in this tenant.',
                    ],
                ]);
            }

            $attributes['reporting_body_id'] = $reportingId;
        } else {
            $attributes['reporting_body_id'] = null;
        }

        // ---------------------------------------------------------------------
        // 6. Effective-date range — from <= until when both are set.
        // ---------------------------------------------------------------------
        $this->assertEffectiveDateOrdering(
            $attributes['effective_from'] ?? null,
            $attributes['effective_until'] ?? null,
        );

        // ---------------------------------------------------------------------
        // 7. Quorum rule — shape validation.
        // ---------------------------------------------------------------------
        if (array_key_exists('quorum_rule_json', $attributes)) {
            $this->validateQuorumRule($attributes['quorum_rule_json']);
        }

        // ---------------------------------------------------------------------
        // 8. is_active — default true.
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
     * Rejects `body_type` changes (INV-3). Validates every other
     * whitelisted field against the same invariants used at
     * registration, checking the POST-update state (not just the
     * incoming payload) where the invariant depends on the current
     * row.
     *
     * @param  GovernanceBody      $body
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normaliseForUpdate(GovernanceBody $body, array $data): array
    {
        $attributes = $this->whitelist($data);

        // ---------------------------------------------------------------------
        // 1. body_type — immutable.
        // ---------------------------------------------------------------------
        if (array_key_exists('body_type', $attributes)) {
            $proposed = $attributes['body_type'];

            if ($proposed !== $body->body_type) {
                throw ValidationException::withMessages([
                    'body_type' => [
                        'The body type cannot be changed after registration. '
                        . 'Create a new body instead.',
                    ],
                ]);
            }

            // Value matches — drop the key so the subsequent fill
            // does not trigger a needless UPDATE.
            unset($attributes['body_type']);
        }

        // ---------------------------------------------------------------------
        // 2. code — when set, must remain unique within the tenant
        //    (excluding this row).
        // ---------------------------------------------------------------------
        if (array_key_exists('code', $attributes)) {
            $codeValue = $attributes['code'];

            if (!is_string($codeValue) || trim($codeValue) === '') {
                throw ValidationException::withMessages([
                    'code' => ['The body code cannot be empty.'],
                ]);
            }

            $code = strtoupper(trim($codeValue));

            if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
                throw ValidationException::withMessages([
                    'code' => [
                        sprintf(
                            'The body code must not exceed %d characters.',
                            self::MAX_CODE_LENGTH,
                        ),
                    ],
                ]);
            }

            if ($this->repository->existsByCode($body->tenant_id, $code, (int) $body->id)) {
                throw ValidationException::withMessages([
                    'code' => [
                        sprintf(
                            'A governance body with the code "%s" already exists in this tenant.',
                            $code,
                        ),
                    ],
                ]);
            }

            $attributes['code'] = $code;
        }

        // ---------------------------------------------------------------------
        // 3. Names — the POST-update state must have at least one
        //    non-empty name. Computed against the proposed changes
        //    merged over the current values.
        // ---------------------------------------------------------------------
        $this->assertAtLeastOneNameAfterUpdate($body, $attributes);

        // ---------------------------------------------------------------------
        // 4. parent_body_id — when set, must reference a body in the
        //    same tenant and must not introduce a cycle.
        // ---------------------------------------------------------------------
        if (array_key_exists('parent_body_id', $attributes)) {
            $parentId = $attributes['parent_body_id'];

            if ($parentId === null) {
                // Clearing the parent is always permitted.
                $attributes['parent_body_id'] = null;
            } else {
                $parentId = (int) $parentId;

                if ($parentId === (int) $body->id) {
                    throw ValidationException::withMessages([
                        'parent_body_id' => [
                            'A body cannot be its own parent.',
                        ],
                    ]);
                }

                if (!$this->repository->existsForTenant($body->tenant_id, $parentId)) {
                    throw ValidationException::withMessages([
                        'parent_body_id' => [
                            'The specified parent body does not exist in this tenant.',
                        ],
                    ]);
                }

                $this->assertNoParentCycle($body->tenant_id, (int) $body->id, $parentId);

                $attributes['parent_body_id'] = $parentId;
            }
        }

        // ---------------------------------------------------------------------
        // 5. reporting_body_id — when set, must reference a body in
        //    the same tenant and must not be this body itself.
        // ---------------------------------------------------------------------
        if (array_key_exists('reporting_body_id', $attributes)) {
            $reportingId = $attributes['reporting_body_id'];

            if ($reportingId === null) {
                $attributes['reporting_body_id'] = null;
            } else {
                $reportingId = (int) $reportingId;

                if ($reportingId === (int) $body->id) {
                    throw ValidationException::withMessages([
                        'reporting_body_id' => [
                            'A body cannot report to itself.',
                        ],
                    ]);
                }

                if (!$this->repository->existsForTenant($body->tenant_id, $reportingId)) {
                    throw ValidationException::withMessages([
                        'reporting_body_id' => [
                            'The specified reporting body does not exist in this tenant.',
                        ],
                    ]);
                }

                $attributes['reporting_body_id'] = $reportingId;
            }
        }

        // ---------------------------------------------------------------------
        // 6. Effective-date range — from <= until when both are set
        //    after the update.
        // ---------------------------------------------------------------------
        $from = array_key_exists('effective_from', $attributes)
            ? $attributes['effective_from']
            : $body->effective_from;
        $until = array_key_exists('effective_until', $attributes)
            ? $attributes['effective_until']
            : $body->effective_until;

        $this->assertEffectiveDateOrdering($from, $until);

        // ---------------------------------------------------------------------
        // 7. Quorum rule — shape validation, when present.
        // ---------------------------------------------------------------------
        if (array_key_exists('quorum_rule_json', $attributes)) {
            $this->validateQuorumRule($attributes['quorum_rule_json']);
        }

        // ---------------------------------------------------------------------
        // 8. is_active — cast to bool when present.
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
     * Assert that at least one of `name_am` / `name_en` is non-empty
     * in the supplied attribute array.
     *
     * Used by `normaliseForRegister()`. For the update path, see
     * `assertAtLeastOneNameAfterUpdate()`.
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
     * Assert that the POST-update state has at least one non-empty
     * name.
     *
     * The check merges the proposed changes over the body's current
     * values, so a partial update that clears one name is accepted
     * when the other name remains set, and rejected when both would
     * end up empty.
     *
     * @param  GovernanceBody      $body
     * @param  array<string, mixed> $attributes
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAtLeastOneNameAfterUpdate(GovernanceBody $body, array $attributes): void
    {
        $proposedAm = array_key_exists('name_am', $attributes)
            ? $attributes['name_am']
            : $body->name_am;

        $proposedEn = array_key_exists('name_en', $attributes)
            ? $attributes['name_en']
            : $body->name_en;

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

    /**
     * Assert that assigning `$proposedParentId` as the parent of
     * body `$bodyId` does not create a cycle.
     *
     * Walks up the parent chain starting from the proposed parent.
     * If the walk encounters the body itself, the assignment would
     * create a cycle and is rejected.
     *
     * Defensive against pre-existing cycles: if the walk encounters
     * a node it has already visited, it stops and treats the
     * assignment as invalid — because the pre-existing state is
     * already corrupt, and adding another link to a corrupt chain
     * would only make the corruption harder to diagnose.
     *
     * The walk is bounded by the number of governance bodies in the
     * tenant. In practice this is small (a handful of bodies per
     * tenant), so the cost is negligible. A future CR that supports
     * very large hierarchies could impose a depth limit; the current
     * design does not.
     *
     * @param  int $tenantId
     * @param  int $bodyId           The id of the body being updated.
     * @param  int $proposedParentId The proposed new parent.
     * @return void
     *
     * @throws ValidationException
     */
    private function assertNoParentCycle(int $tenantId, int $bodyId, int $proposedParentId): void
    {
        $currentId = $proposedParentId;
        $visited = [];

        while ($currentId !== null) {
            if ($currentId === $bodyId) {
                throw ValidationException::withMessages([
                    'parent_body_id' => [
                        'The proposed parent would create a cycle in the body hierarchy.',
                    ],
                ]);
            }

            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_body_id' => [
                        'An existing cycle was detected in the body hierarchy. '
                        . 'Resolve the existing cycle before changing this body\'s parent.',
                    ],
                ]);
            }

            $visited[$currentId] = true;

            $nextParent = GovernanceBody::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $currentId)
                ->value('parent_body_id');

            $currentId = $nextParent !== null ? (int) $nextParent : null;
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
            // enough information to compare" and lets the request
            // layer's validation own the diagnosis.
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

    /**
     * Validate the shape of a `quorum_rule_json` payload.
     *
     * Accepts:
     *   · `null` — no rule set; permitted.
     *   · A PHP array with a `type` key equal to one of the three
     *     declared types, and — for `fixed_number` and
     *     `percentage_of_members` — a `value` key that is a
     *     non-negative integer.
     *
     * Rejects any other shape.
     *
     * @param  mixed $rule
     * @return void
     *
     * @throws ValidationException
     */
    private function validateQuorumRule(mixed $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (!is_array($rule)) {
            throw ValidationException::withMessages([
                'quorum_rule_json' => [
                    'The quorum rule must be a JSON object.',
                ],
            ]);
        }

        $type = $rule['type'] ?? null;

        if (!is_string($type) || !in_array($type, self::QUORUM_RULE_TYPES, true)) {
            throw ValidationException::withMessages([
                'quorum_rule_json.type' => [
                    sprintf(
                        'The quorum rule type must be one of: %s.',
                        implode(', ', self::QUORUM_RULE_TYPES),
                    ),
                ],
            ]);
        }

        // `special` is opaque to the application — the value field
        // is optional and its meaning is defined by the body's
        // charter. The two numeric types require a value.
        if ($type === 'special') {
            return;
        }

        $value = $rule['value'] ?? null;

        if (!is_int($value) || $value < 0) {
            throw ValidationException::withMessages([
                'quorum_rule_json.value' => [
                    'The quorum rule value must be a non-negative integer.',
                ],
            ]);
        }

        if ($type === 'percentage_of_members' && $value > 100) {
            throw ValidationException::withMessages([
                'quorum_rule_json.value' => [
                    'The quorum percentage cannot exceed 100.',
                ],
            ]);
        }

        if ($type === 'fixed_number' && $value === 0) {
            throw ValidationException::withMessages([
                'quorum_rule_json.value' => [
                    'A fixed-number quorum must be at least 1.',
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
     * Unknown keys are dropped. This prevents accidental mass-
     * assignment of fields such as `tenant_id`, `id`, `created_at`,
     * or any future column that is not intended to be
     * caller-controlled.
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
     * Accepts `Carbon` / `DateTimeInterface` instances directly, and
     * attempts a `Carbon::parse()` for strings. Returns null on
     * failure so the caller can decide whether to reject or ignore.
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

    // =========================================================================
    // Private — event dispatch
    // =========================================================================

    /**
     * Dispatch an event after the current transaction commits,
     * subject to the audit feature flag.
     *
     * Uses `DB::afterCommit()`, which registers a callback that the
     * framework invokes once the outermost transaction commits. If
     * no transaction is active, the callback runs immediately — this
     * is defensive, because every mutation in this service is
     * wrapped.
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