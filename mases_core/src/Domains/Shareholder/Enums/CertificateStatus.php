<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Enums;

/**
 * CertificateStatus
 *
 * The controlled lifecycle states of a share certificate in MASES.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * The four states are established by the Phase 2A pre-flight decision:
 *
 *     draft
 *     issued
 *     cancelled
 *     replaced
 *
 * These values are stored on `share_certificates.status` (migration 2A.41)
 * and are the only values accepted by the CertificateService (2A.15).
 * Adding, removing, or renaming a case is a Change Request under Master
 * Prompt Section 58, because it changes the meaning of historical rows in
 * the `share_certificates` table.
 *
 * =============================================================================
 * Lifecycle model
 * =============================================================================
 * The legal transitions are:
 *
 *     draft      ──►  issued
 *                ──►  cancelled
 *
 *     issued     ──►  cancelled
 *                ──►  replaced
 *
 *     cancelled  ──►  (terminal)
 *
 *     replaced   ──►  (terminal)
 *
 * Interpreted:
 *
 *   - A newly prepared certificate starts in `draft`. It may either be
 *     issued (activated) or cancelled (abandoned) before issuance.
 *
 *   - Once `issued`, the certificate is an active instrument. It may be
 *     cancelled (withdrawn) or replaced (superseded by a new certificate
 *     covering the same shares).
 *
 *   - `cancelled` and `replaced` are TERMINAL states. A certificate that
 *     has reached either of them does not return to an active state. This
 *     is a deliberate design choice: the historical certificate remains
 *     visible for audit, and the current state of the holding is always
 *     reflected by the ledger (D16-CORE), never by reverse-mutating a
 *     certificate's status.
 *
 *     If a certificate is cancelled or replaced, and the shareholder later
 *     requires a fresh certificate for the same holding, a NEW certificate
 *     is issued — not a status change on the old one.
 *
 *   - Self-transitions (`issued → issued`) are never permitted. If a
 *     "re-issue without change" were ever required, it must be modelled as
 *     a replace-and-issue pair, which forces a new row and preserves the
 *     audit chain.
 *
 * =============================================================================
 * Why there is no `cancelled` → `draft` transition
 * =============================================================================
 * Some systems allow a cancelled record to be "reactivated". MASES does not.
 * Cancellation is a recorded business fact with a timestamp and an actor
 * (see the `audit_logs` entry written by AuditShareholderEvent, 2A.35).
 * Reversing it would silently invalidate the audit trail. If the business
 * later needs the shares to be certificated again, that is a NEW certificate
 * — not an undo of the cancellation.
 *
 * =============================================================================
 * Relationship to ShareHolding / ledger
 * =============================================================================
 * The certificate is a DERIVED artefact: it records that a shareholder holds
 * a quantity of a share class. It does not itself change the holding balance.
 * Only ShareHoldingEntry rows (via ShareHoldingService, 2A.14) change the
 * balance, per D16-CORE.
 *
 * This means the certificate lifecycle and the ledger lifecycle are
 * independent:
 *
 *   - A certificate can be cancelled while the underlying holding remains.
 *     (Example: the physical certificate was lost; the shares are intact.)
 *
 *   - A certificate can be replaced while the underlying holding is
 *     unchanged. (Example: the shareholder's legal name was corrected; a
 *     new certificate is issued to reflect it.)
 *
 *   - A certificate's existence is NOT a precondition for the holding to
 *     be valid. The ledger is authoritative.
 *
 * =============================================================================
 * Localization
 * =============================================================================
 * As with the other domain enums, this enum does NOT provide display
 * strings. Localized labels belong to the resource layer (2A.28), resolved
 * through translation keys of the form
 * `shareholder.certificate.status.{value}`.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Enums
 */
enum CertificateStatus: string
{
    /**
     * A prepared certificate that has not yet been issued.
     *
     * Non-terminal. May transition to Issued or Cancelled.
     *
     * In Phase 2A the default status for a newly created certificate is
     * `issued`, not `draft` (see config `shareholder.certificate.default_status`).
     * `draft` is reserved for a future workflow that separates preparation
     * from issuance.
     */
    case Draft = 'draft';

    /**
     * An active, issued certificate.
     *
     * Non-terminal. May transition to Cancelled or Replaced.
     *
     * `isActive()` is true for this case only.
     */
    case Issued = 'issued';

    /**
     * A certificate that has been withdrawn.
     *
     * Terminal. No outgoing transitions.
     */
    case Cancelled = 'cancelled';

    /**
     * A certificate that has been superseded by a newer certificate
     * covering the same holding.
     *
     * Terminal. No outgoing transitions. The replacement is a new
     * certificate row, not a status change on this one.
     */
    case Replaced = 'replaced';

    /**
     * Whether this status represents a certificate that is currently in
     * force.
     *
     * True only for `Issued`. `Draft` is not in force (it has not been
     * issued); `Cancelled` and `Replaced` are no longer in force.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this === self::Issued;
    }

    /**
     * Whether this status is a terminal state from which no further
     * transition is legal.
     *
     * True for `Cancelled` and `Replaced`.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Replaced => true,
            self::Draft, self::Issued       => false,
        };
    }

    /**
     * Whether this status represents a state entered before the certificate
     * has ever been issued.
     *
     * True only for `Draft`. Useful for filtering and for the (future)
     * draft-issuance workflow.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Determine whether a transition from this status to the given status
     * is legal.
     *
     * The transition matrix is documented in the class-level docblock.
     * Callers MUST consult this method before persisting a status change.
     * The CertificateService (2A.15) enforces the rule on every write.
     *
     * @param self $next  The proposed new status.
     * @return bool       True if the transition is permitted.
     */
    public function canTransitionTo(self $next): bool
    {
        // Self-transitions are never permitted. Reissuing without change
        // would silently overwrite history; if a re-issue is required, it
        // must be modelled as a new certificate row.
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Draft     => $next === self::Issued || $next === self::Cancelled,
            self::Issued    => $next === self::Cancelled || $next === self::Replaced,
            self::Cancelled => false,
            self::Replaced  => false,
        };
    }

    /**
     * All raw string values of the enum, in declaration order.
     *
     * Useful for validation whitelists, migration seeds, and tests that
     * assert the vocabulary is unchanged.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases()
        );
    }

    /**
     * All non-terminal statuses.
     *
     * Callers that need to filter for certificates capable of receiving a
     * transition can pass this list to a `whereIn` clause without
     * re-enumerating the cases.
     *
     * @return array<int, self>
     */
    public static function nonTerminal(): array
    {
        return array_values(
            array_filter(
                self::cases(),
                static fn (self $case): bool => !$case->isTerminal(),
            )
        );
    }

    /**
     * All statuses that indicate a certificate is (or may become) in force.
     *
     * Currently returns `Draft` and `Issued`, because a draft certificate
     * is by definition not yet cancelled or replaced. If a future workflow
     * introduces additional live states, this method is the single place
     * to update them.
     *
     * @return array<int, self>
     */
    public static function live(): array
    {
        return array_values(
            array_filter(
                self::cases(),
                static fn (self $case): bool => !$case->isTerminal(),
            )
        );
    }
}