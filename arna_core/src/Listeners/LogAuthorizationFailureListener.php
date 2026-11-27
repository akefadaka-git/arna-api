<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Listeners;

use Illuminate\Support\Facades\Log;
use MaaleArna\Arna\Core\Events\AuthorizationFailureEvent;
use MaaleArna\Arna\Core\Security\Services\SecurityEventService;

/**
 * LogAuthorizationFailureListener
 *
 * Listens for every AuthorizationFailureEvent emitted by the platform and
 * persists a corresponding entry in the `security_events` table via the
 * SecurityEventService.
 *
 * =============================================================================
 * Historical changes are recorded in Git.
 * @package MaaleArna\Arna\Core\Listeners
 * @version 1.0.1
 */
final class LogAuthorizationFailureListener
{
    /**
     * Event type used in the `security_events` table for every denial.
     */
    public const EVENT_TYPE = 'authorization.denied';

    /**
     * Severity assigned to denials involving system-level roles.
     */
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Severity assigned to all other denials.
     */
    public const SEVERITY_WARNING = 'warning';

    /**
     * Role slugs that indicate a system-level privilege.
     *
     * @var array<int, string>
     */
    private const SYSTEM_ROLE_SLUGS = [
        'super_admin',
        'platform_admin',
        'system_admin',
    ];

    /**
     * Permission slug prefixes that indicate administrative scope.
     *
     * @var array<int, string>
     */
    private const SYSTEM_PERMISSION_PREFIXES = [
        'admin.',
        'system.',
    ];

    /**
     * The security event service used to persist the entry.
     *
     * @var SecurityEventService
     */
    private SecurityEventService $securityEvents;

    /**
     * Create a new listener instance.
     *
     * @param SecurityEventService $securityEvents
     */
    public function __construct(SecurityEventService $securityEvents)
    {
        $this->securityEvents = $securityEvents;
    }

    /**
     * Handle the event.
     *
     * Flow:
     *   1. Consult the audit flag. If the flag is off, return without
     *      persisting. The event has already been dispatched by the
     *      middleware — the middleware's own Log::warning() line and the
     *      AuthorizationException still fire. Only the audit-trail copy is
     *      suppressed. See the class docblock §"Revision history" for why
     *      the gate lives here rather than in the service.
     *   2. Compute severity, description, and the persistence payload.
     *   3. Hand off to SecurityEventService::log(), which stores the row
     *      and mirrors it to the application log.
     *   4. Swallow any exception from the above so that a failure in the
     *      audit path never breaks the HTTP request lifecycle.
     *
     * @param AuthorizationFailureEvent $event
     *
     * @return void
     */
    public function handle(AuthorizationFailureEvent $event): void
    {
        // ---------------------------------------------------------------------
        // Audit-flag gate.
        //
        // When the flag is falsy, this listener does nothing. The
        // AuthorizationFailureEvent was already dispatched by the
        // middleware, so any other listener registered against the same
        // event still runs unaffected. Only the persistence step is
        // suppressed.
        //
        // Default of `true` ensures that a missing configuration key (or a
        // configuration file that does not yet define the flag) does not
        // silently disable the audit trail.
        // ---------------------------------------------------------------------
        if (!config('arna.features.audit_enabled', true)) {
            return;
        }

        try {
            $severity = $this->determineSeverity($event);
            $description = $this->buildDescription($event);

            $data = $this->buildEventData($event);

            $this->securityEvents->log(
                eventType: self::EVENT_TYPE,
                data: $data,
                severity: $severity,
                description: $description,
            );
        } catch (\Throwable $e) {
            // The listener must never break the request lifecycle.
            // If anything goes wrong here (unexpected type, DB issue, etc.),
            // we log a diagnostic and continue. SecurityEventService::log()
            // already has its own internal try/catch, but we add a second
            // layer to be safe against programming errors above it.
            Log::error('Failed to persist authorization failure security event', [
                'error'      => $e->getMessage(),
                'check_type' => $event->checkType,
                'check_value' => $event->checkValue,
                'user_id'    => $event->userId,
                'tenant_id'  => $event->tenantId,
            ]);
        }
    }

    /**
     * Determine the severity level for a given failure event.
     *
     * @param AuthorizationFailureEvent $event
     *
     * @return string
     */
    private function determineSeverity(AuthorizationFailureEvent $event): string
    {
        // A denial involving a system-level role is always critical.
        if ($event->checkType === 'role'
            && in_array($event->checkValue, self::SYSTEM_ROLE_SLUGS, true)
        ) {
            return self::SEVERITY_CRITICAL;
        }

        // A denial involving a system-scoped permission is critical.
        if ($event->checkType === 'permission') {
            foreach (self::SYSTEM_PERMISSION_PREFIXES as $prefix) {
                if (str_starts_with($event->checkValue, $prefix)) {
                    return self::SEVERITY_CRITICAL;
                }
            }
        }

        // Every other denial is a warning.
        return self::SEVERITY_WARNING;
    }

    /**
     * Build the structured data payload stored in the `security_events.event_data`
     * JSON column and passed to the log context.
     *
     * The `authorization` key contains the structured denial context; the
     * top-level keys are consumed by SecurityEventService to populate the
     * dedicated columns (`user_id`, `tenant_id`, `ip_address`, etc.).
     *
     * Both the top-level array and the nested `authorization` array are
     * filtered through the same rule: drop any entry whose value is `null`
     * or the empty string. This guarantees the persisted JSON contains only
     * keys that carry meaningful values, matching the contract asserted by
     * AuthorizationFailureEventTest — a denial for which `action` and
     * `resource_type` are not applicable must not persist those keys at all
     * (not persist them with a null value).
     *
     * The `occurred_at` key is always a non-empty string produced by
     * `DateTimeInterface::format(DateTimeInterface::ATOM)` and therefore
     * survives both filters unconditionally.
     *
     * @param AuthorizationFailureEvent $event
     *
     * @return array<string, mixed>
     */
    private function buildEventData(AuthorizationFailureEvent $event): array
    {
        // Filter the nested authorization payload first, so the outer
        // wrapper sees an already-clean array. See the method docblock for
        // the rationale.
        $authorization = array_filter(
            $event->toSecurityEventData(),
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );

        return array_filter(
            [
                'user_id'       => $event->userId,
                'tenant_id'     => $event->tenantId,
                'request_id'    => $event->requestId,
                'ip'            => $event->ip,
                'user_agent'    => $event->userAgent,
                'authorization' => $authorization,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * Build a human-readable description for the security event.
     *
     * The description intentionally avoids any sensitive value and stays
     * stable across languages — localization is applied at display time,
     * not at persistence time.
     *
     * @param AuthorizationFailureEvent $event
     *
     * @return string
     */
    private function buildDescription(AuthorizationFailureEvent $event): string
    {
        $parts = [$event->description()];

        if ($event->application !== null) {
            $parts[] = sprintf('application=%s', $event->application);
        }

        if ($event->resourceType !== null) {
            $resource = $event->resourceType;
            if ($event->resourceId !== null) {
                $resource .= '#' . $event->resourceId;
            }
            $parts[] = sprintf('resource=%s', $resource);
        }

        return implode(' | ', $parts);
    }
}