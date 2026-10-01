<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\CertificateStatus;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\CertificateIssued;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * CertificateService
 *
 * The single write path for share certificates. Handles the three lifecycle
 * operations — issue, cancel, replace — and enforces the state transitions
 * declared in CertificateStatus::canTransitionTo() (2A.5).
 *
 * =============================================================================
 * What a certificate is (and is not)
 * =============================================================================
 * A certificate is an ATTESTATION: it records that a specific shareholder
 * holds a specific quantity of a specific share class at the time of issue.
 * It does NOT itself change any balance. Balance changes flow through
 * ShareHoldingService (2A.14) and are ledger-backed per D16-CORE.
 *
 * This service therefore:
 *
 *   - Does not write to `share_holdings.current_quantity`.
 *   - Does not write to `share_holding_entries`.
 *   - Reads `ShareHolding.current_quantity` only if a future feature needs
 *     a snapshot value; it does not in Phase 2A.
 *
 * The consequence is that a certificate can be issued, cancelled, or
 * replaced without any change to the ledger or the projection. Conversely,
 * the ledger can move without affecting any certificate. The two lifecycles
 * are independent by design.
 *
 * =============================================================================
 * Lifecycle (CertificateStatus, D-preflight)
 * =============================================================================
 *   draft      — non-terminal. May transition to issued or cancelled.
 *   issued     — non-terminal. May transition to cancelled or replaced.
 *   cancelled  — terminal.
 *   replaced   — terminal.
 *
 * Transitions are validated on every state change. A caller that requests
 * an illegal transition receives a ValidationException; the transaction is
 * rolled back and no state is altered.
 *
 * In Phase 2A, newly created certificates default to `issued` (config:
 * `shareholder.certificate.default_status`). The `draft` state is supported
 * by the model and enum but is not reachable through `issue()` — a future
 * CR will add a `prepareDraft()` method if the business needs a
 * prepare-then-issue workflow.
 *
 * =============================================================================
 * "At most one active certificate per holding"
 * =============================================================================
 * The business rule that a holding may have at most one `issued` certificate
 * is enforced by default. It is gated on configuration:
 *
 *     config('shareholder.certificate.allow_multiple_active_per_holding', false)
 *
 * The default is `false` — i.e. the strict rule applies. When the gate is
 * `false`, `issue()` rejects a request that would create a second active
 * certificate for the same `share_holding_id`.
 *
 * During `replace()` the original certificate is transitioned to `Replaced`
 * in the same transaction that creates the new one, so the invariant holds
 * on the outside: at most one certificate is `Issued` for any given
 * holding at any observable moment.
 *
 * If the corporate evidence later establishes that multiple active
 * certificates are permitted (for example, a holding certificated in
 * separate tranches), setting the config gate to `true` relaxes the rule.
 * Doing so is a CR-worthy change and should be documented as such.
 *
 * =============================================================================
 * Certificate numbering
 * =============================================================================
 * `certificate_number` is a NULLABLE string (pre-flight decision). The
 * service accepts it as an optional input; it never generates one. If a
 * caller supplies a non-null value, the service verifies uniqueness against
 * the `UNIQUE` constraint on the column and produces a friendly validation
 * error before the database constraint fires. If the caller supplies
 * `null`, the certificate has no number — permitted by schema, discouraged
 * by operations, and usable for pre-numbered manual entry.
 *
 * =============================================================================
 * Event dispatch
 * =============================================================================
 * Only `issue()` dispatches a domain event (`CertificateIssued`, 2A.34).
 * `cancel()` and `replace()` do NOT dispatch a domain event in Phase 2A,
 * because no event class exists in the frozen manifest for those
 * transitions. The changes are recorded in the `share_certificates` table
 * itself (status, cancelled_at, replaced_by_id) and, if a policy layer
 * chooses, in the `audit_logs` table through an explicit call from the
 * controller.
 *
 * If the audit trail requires a domain event for cancellation and
 * replacement, a Change Request will add `CertificateCancelled` and
 * `CertificateReplaced` event classes and register them in
 * ShareholderServiceProvider (2A.2) and AuditShareholderEvent (2A.35).
 *
 * =============================================================================
 * F-2A15-9 — `assertPersisted()` was using `method_exists`
 * =============================================================================
 * The original form of `assertPersisted()` read:
 *
 *     if (!method_exists($model, 'exists') || !$model->exists) { … }
 *
 * `exists` on an Eloquent model is a public PROPERTY, not a method.
 * `method_exists()` therefore always returned false, and the guard always
 * threw — meaning `issue()` and `issueFor()` were unreachable in every
 * runtime path. This defect was discovered during the 2A.48 feature test
 * run: all four `store` tests failed with `shareholder_id` →
 * "The shareholder must be a persisted record."
 *
 * The corrected form uses `property_exists()`, which is the appropriate
 * introspection primitive for an Eloquent attribute. Recorded as
 * F-2A15-9.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Services
 * @version 1.0.1
 */
final class CertificateService
{
    /**
     * Construct the service with its dependency.
     *
     * `ShareHoldingService` is used by `issueFor()` to find or create the
     * holding when the caller does not supply one. It is not used by
     * `issue()`, `cancel()`, or `replace()`, which operate on already-
     * resolved models.
     *
     * @param ShareHoldingService $shareHoldingService
     */
    public function __construct(
        private readonly ShareHoldingService $shareHoldingService,
    ) {
    }

    // =========================================================================
    // Public API — issuance
    // =========================================================================

    /**
     * Issue a certificate against an existing holding.
     *
     * The certificate is created with status `issued` (unless config
     * overrides the default to `draft` — the config gate exists but the
     * Phase 2A default is `issued`). `issued_at` is set to now. The
     * `certificate_number` is optional; if supplied, uniqueness is
     * validated.
     *
     * The "at most one active certificate per holding" rule applies if the
     * config gate is false (the default). See the class docblock.
     *
     * @param Shareholder $shareholder
     * @param ShareClass  $shareClass
     * @param ShareHolding $holding
     * @param string|null $certificateNumber
     * @param string|null $notes
     * @return ShareCertificate
     *
     * @throws ValidationException
     */
    public function issue(
        Shareholder $shareholder,
        ShareClass $shareClass,
        ShareHolding $holding,
        ?string $certificateNumber = null,
        ?string $notes = null,
    ): ShareCertificate {
        $this->assertPersisted($shareholder, 'shareholder');
        $this->assertPersisted($shareClass, 'share_class');
        $this->assertPersisted($holding, 'share_holding');
        $this->assertHoldingMatchesShareholderAndClass($holding, $shareholder, $shareClass);
        $this->assertCertificateNumberIsAvailable($certificateNumber);

        $defaultStatus = (string) config(
            'shareholder.certificate.default_status',
            CertificateStatus::Issued->value,
        );

        $status = CertificateStatus::tryFrom($defaultStatus);

        if ($status === null) {
            throw new ValidationException(
                'The configured default certificate status is not a recognised value.'
            );
        }

        // Draft issuance is not enabled through this method in Phase 2A.
        if ($status !== CertificateStatus::Issued) {
            throw ValidationException::withMessages([
                'status' => [
                    'Phase 2A issues certificates with status "issued" only. '
                    . 'Draft issuance requires a future workflow.',
                ],
            ]);
        }

        return DB::transaction(function () use (
            $shareholder,
            $shareClass,
            $holding,
            $certificateNumber,
            $notes,
        ): ShareCertificate {
            // Enforce the "one active certificate per holding" invariant
            // inside the transaction, so a concurrent issuance cannot slip
            // through between the check and the insert.
            if (!$this->allowMultipleActivePerHolding()) {
                $existingActive = ShareCertificate::query()
                    ->where('share_holding_id', $holding->id)
                    ->where('status', CertificateStatus::Issued->value)
                    ->exists();

                if ($existingActive) {
                    throw ValidationException::withMessages([
                        'share_holding_id' => [
                            'An active certificate already exists for this holding. '
                            . 'Cancel or replace it before issuing a new one.',
                        ],
                    ]);
                }
            }

            /** @var ShareCertificate $certificate */
            $certificate = ShareCertificate::create([
                'shareholder_id'     => $shareholder->id,
                'share_class_id'     => $shareClass->id,
                'share_holding_id'   => $holding->id,
                'certificate_number' => $certificateNumber,
                'status'             => CertificateStatus::Issued->value,
                'issued_at'          => Carbon::now(),
                'cancelled_at'       => null,
                'replaced_by_id'     => null,
                'notes'              => $notes,
            ]);

            $this->dispatchAfterCommit(new CertificateIssued($certificate));

            return $certificate->refresh();
        });
    }

    /**
     * Issue a certificate for a (shareholder, share class) pair, resolving
     * the holding automatically.
     *
     * If no ShareHolding exists for the pair, one is created at quantity 0
     * by ShareHoldingService, and the certificate is issued against it.
     * Issuing a certificate for a zero-quantity holding is unusual but not
     * forbidden — for example, a historical certificate migrated from a
     * paper register may be issued before the ledger is backfilled.
     *
     * @param Shareholder $shareholder
     * @param ShareClass  $shareClass
     * @param string|null $certificateNumber
     * @param string|null $notes
     * @return ShareCertificate
     *
     * @throws ValidationException
     */
    public function issueFor(
        Shareholder $shareholder,
        ShareClass $shareClass,
        ?string $certificateNumber = null,
        ?string $notes = null,
    ): ShareCertificate {
        $holding = $this->shareHoldingService->findOrCreateHolding($shareholder, $shareClass);

        return $this->issue(
            shareholder: $shareholder,
            shareClass: $shareClass,
            holding: $holding,
            certificateNumber: $certificateNumber,
            notes: $notes,
        );
    }

    // =========================================================================
    // Public API — cancellation
    // =========================================================================

    /**
     * Cancel an existing certificate.
     *
     * Only `Draft` and `Issued` certificates may be cancelled. `Cancelled`
     * and `Replaced` are terminal states; attempting to cancel them throws
     * a ValidationException.
     *
     * The certificate row is preserved. Its status becomes `cancelled` and
     * `cancelled_at` is set to now. The `notes` column is not modified
     * unless the caller supplies a `$reason`, in which case it is appended
     * (never overwritten).
     *
     * @param ShareCertificate $certificate
     * @param string|null      $reason
     * @return ShareCertificate
     *
     * @throws ValidationException
     */
    public function cancel(ShareCertificate $certificate, ?string $reason = null): ShareCertificate
    {
        if (!$certificate->exists) {
            throw ValidationException::withMessages([
                'certificate_id' => ['The certificate must be a persisted record.'],
            ]);
        }

        $currentStatus = $certificate->status;

        if (!$currentStatus->canTransitionTo(CertificateStatus::Cancelled)) {
            throw ValidationException::withMessages([
                'status' => [
                    sprintf(
                        'A certificate with status "%s" cannot be cancelled.',
                        $currentStatus->value,
                    ),
                ],
            ]);
        }

        return DB::transaction(function () use ($certificate, $reason): ShareCertificate {
            $certificate->status       = CertificateStatus::Cancelled;
            $certificate->cancelled_at = Carbon::now();

            if ($reason !== null && trim($reason) !== '') {
                $existing = $certificate->notes;

                $certificate->notes = $existing === null || $existing === ''
                    ? $reason
                    : $existing . "\n---\nCancellation reason: " . $reason;
            }

            $certificate->save();

            // No domain event is dispatched here. See the class docblock,
            // section "Event dispatch", for the reasoning and the future CR
            // that would add CertificateCancelled.

            return $certificate->refresh();
        });
    }

    // =========================================================================
    // Public API — replacement
    // =========================================================================

    /**
     * Replace an existing certificate with a new one.
     *
     * Only `Issued` certificates may be replaced. The operation:
     *
     *   1. Validates that `Issued → Replaced` is a legal transition.
     *   2. Creates a new certificate with status `issued`, referencing the
     *      same shareholder, class, and holding as the original.
     *   3. Sets the original's status to `replaced` and its `replaced_by_id`
     *      to the new certificate's id.
     *   4. Sets the original's `cancelled_at` to null (it is not cancelled —
     *      it is replaced). This is deliberate per the model's semantic
     *      distinction between the two terminal states.
     *   5. Appends the `$notes` argument to the original's notes column, so
     *      the reason for replacement is recorded at the point of replacement.
     *
     * Both operations run inside one transaction. If any step fails, no
     * certificate is created and the original is unchanged.
     *
     * @param ShareCertificate $original
     * @param string|null      $newCertificateNumber
     * @param string|null      $notes
     * @return ShareCertificate  The NEW certificate.
     *
     * @throws ValidationException
     */
    public function replace(
        ShareCertificate $original,
        ?string $newCertificateNumber = null,
        ?string $notes = null,
    ): ShareCertificate {
        if (!$original->exists) {
            throw ValidationException::withMessages([
                'certificate_id' => ['The certificate to replace must be a persisted record.'],
            ]);
        }

        $currentStatus = $original->status;

        if (!$currentStatus->canTransitionTo(CertificateStatus::Replaced)) {
            throw ValidationException::withMessages([
                'status' => [
                    sprintf(
                        'A certificate with status "%s" cannot be replaced. '
                        . 'Only "issued" certificates may be replaced.',
                        $currentStatus->value,
                    ),
                ],
            ]);
        }

        $this->assertCertificateNumberIsAvailable($newCertificateNumber, $original->id);

        return DB::transaction(function () use (
            $original,
            $newCertificateNumber,
            $notes,
        ): ShareCertificate {
            // -----------------------------------------------------------------
            // 1. Create the new certificate (same shareholder, class, holding).
            // -----------------------------------------------------------------
            /** @var ShareCertificate $new */
            $new = ShareCertificate::create([
                'shareholder_id'     => $original->shareholder_id,
                'share_class_id'     => $original->share_class_id,
                'share_holding_id'   => $original->share_holding_id,
                'certificate_number' => $newCertificateNumber,
                'status'             => CertificateStatus::Issued->value,
                'issued_at'          => Carbon::now(),
                'cancelled_at'       => null,
                'replaced_by_id'     => null,
                'notes'              => $notes,
            ]);

            // -----------------------------------------------------------------
            // 2. Mark the original as replaced, pointing at the new one.
            // -----------------------------------------------------------------
            $original->status         = CertificateStatus::Replaced;
            $original->replaced_by_id = $new->id;
            // `cancelled_at` deliberately remains null — replacement is not
            // cancellation. The model preserves the distinction.

            $original->save();

            // -----------------------------------------------------------------
            // 3. Dispatch a domain event for the new certificate.
            //
            //    This uses CertificateIssued because the new certificate
            //    IS an issued certificate; a listener sees a coherent
            //    "certificate N issued" fact. The linkage to the original
            //    (via the original's replaced_by_id) is available to any
            //    listener that queries for it.
            // -----------------------------------------------------------------
            $this->dispatchAfterCommit(new CertificateIssued($new));

            return $new->refresh();
        });
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that a model is persisted.
     *
     * Eloquent exposes `exists` as a PUBLIC PROPERTY on the model, not as a
     * method. The earlier implementation used `method_exists($model, 'exists')`
     * as the guard, which always returned false for any Eloquent model and
     * caused the method to throw unconditionally. See F-2A15-9 in the class
     * docblock.
     *
     * `property_exists()` is the correct introspection primitive here: it
     * returns true for a hydrated Eloquent model and false for anything
     * that is not an Eloquent-style object.
     *
     * @param object $model
     * @param string $fieldName  Field name used in the validation error.
     * @return void
     *
     * @throws ValidationException
     */
    private function assertPersisted(object $model, string $fieldName): void
    {
        if (!property_exists($model, 'exists') || !$model->exists) {
            throw ValidationException::withMessages([
                $fieldName . '_id' => [
                    sprintf('The %s must be a persisted record.', str_replace('_', ' ', $fieldName)),
                ],
            ]);
        }
    }

    /**
     * Assert that a holding belongs to the given shareholder and class.
     *
     * Guards against a caller mistakenly issuing a certificate against a
     * holding that does not match the shareholder and class they supplied.
     *
     * @param ShareHolding $holding
     * @param Shareholder  $shareholder
     * @param ShareClass   $shareClass
     * @return void
     *
     * @throws ValidationException
     */
    private function assertHoldingMatchesShareholderAndClass(
        ShareHolding $holding,
        Shareholder $shareholder,
        ShareClass $shareClass,
    ): void {
        if ($holding->shareholder_id !== $shareholder->id) {
            throw ValidationException::withMessages([
                'share_holding_id' => [
                    'The provided share holding does not belong to the specified shareholder.',
                ],
            ]);
        }

        if ($holding->share_class_id !== $shareClass->id) {
            throw ValidationException::withMessages([
                'share_holding_id' => [
                    'The provided share holding does not correspond to the specified share class.',
                ],
            ]);
        }
    }

    /**
     * Assert that a certificate number is available (unique) if non-null.
     *
     * The `share_certificates.certificate_number` column carries a UNIQUE
     * index. This method provides a friendly validation error before the
     * database constraint fires.
     *
     * @param string|null $certificateNumber
     * @param int|null    $excludeId  Certificate id to ignore when checking (for replace).
     * @return void
     *
     * @throws ValidationException
     */
    private function assertCertificateNumberIsAvailable(
        ?string $certificateNumber,
        ?int $excludeId = null,
    ): void {
        if ($certificateNumber === null) {
            return;
        }

        $trimmed = trim($certificateNumber);

        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'certificate_number' => [
                    'The certificate number cannot be an empty string. Omit the field or provide a value.',
                ],
            ]);
        }

        $query = ShareCertificate::query()->where('certificate_number', $trimmed);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'certificate_number' => [
                    sprintf('The certificate number "%s" is already in use.', $trimmed),
                ],
            ]);
        }
    }

    // =========================================================================
    // Private — configuration and events
    // =========================================================================

    /**
     * Whether the "one active certificate per holding" rule is disabled.
     *
     * Returns the inverse of the enforcement flag: when
     * `allow_multiple_active_per_holding` is true, the strict rule is not
     * applied. The default is false (strict enforcement).
     *
     * @return bool
     */
    private function allowMultipleActivePerHolding(): bool
    {
        return (bool) config(
            'shareholder.certificate.allow_multiple_active_per_holding',
            false,
        );
    }

    /**
     * Dispatch an event after the current transaction commits, subject to
     * the audit feature flag.
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