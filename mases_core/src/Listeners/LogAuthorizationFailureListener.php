<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Listeners;

use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Events\AuthorizationFailureEvent;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;

/**
 * LogAuthorizationFailureListener
 *
 * Listens for every AuthorizationFailureEvent emitted by the platform and
 * persists a corresponding entry in the `security_events` table via the
 * SecurityEventService.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Registered in CoreServiceProvider::boot():
 *
 *              Event::listen(
 *                  AuthorizationFailureEvent::class,
 *                  LogAuthorizationFailureListener::class
 *              );
 *
 *          Mapped the event to the `security_events.event_type` value
 *          `authorization.denied`, computed a severity, and persisted a
 *          payload that wrapped `AuthorizationFailureEvent::toSecurityEventData()`
 *          under a top-level `authorization` key. No version tag was
 *          recorded in the file at the time of initial delivery; the
 *          designation `1.0.0` is applied retroactively in this revision
 *          entry so that future changes have a baseline to diff against.
 *
 * 1.0.1  — CR-3 corrections. Two changes, both in this file:
 *
 *          (a) AUDIT-FLAG GATE — `handle()` now short-circuits and returns
 *              without calling `SecurityEventService::log()` when
 *              `config('mases.features.audit_enabled')` is falsy.
 *
 *              WHY HERE AND NOT IN THE SERVICE
 *              -------------------------------
 *              `SecurityEventService::log()` deliberately does not consult
 *              the audit flag. Its docblock states the contract in full:
 *              "Security events are **always** logged, regardless of the
 *              `audit_enabled` feature flag." That contract is load-bearing
 *              for the login, logout, password-reset, MFA, and account-state
 *              event families — all of which must be persisted even if the
 *              audit flag is turned off, because they are the primary
 *              evidence trail for authentication and account-lifecycle
 *              activity.
 *
 *              The authorization-denial event is a different case. The
 *              `AuthorizationFailureEventTest` establishes the platform's
 *              contract: denials of authorization checks are audit-scope,
 *              not security-scope, and must be suppressed when the audit
 *              flag is off. The listener is the correct level at which to
 *              apply that distinction — it is the component that decides
 *              whether a specific event class should be routed to the
 *              audit trail at all. Pushing the check down into the service
 *              would either contradict the service's own contract or
 *              require the service to distinguish between event types,
 *              which would couple it to the taxonomy of its callers.
 *
 *              Default is `true` (enabled), so a missing configuration key
 *              does not silently disable the audit trail.
 *
 *          (b) NULL-STRIPPED NESTED PAYLOAD — `buildEventData()` now filters
 *              null and empty-string entries from the nested
 *              `authorization` array before wrapping it.
 *
 *              WHY THIS WAS NEEDED
 *              -------------------
 *              `AuthorizationFailureEvent::toSecurityEventData()` returns
 *              seven keys unconditionally:
 *
 *                  check_type, check_value, application,
 *                  resource_type, resource_id, action, occurred_at
 *
 *              Several of those keys carry `null` for denials where the
 *              value is not applicable. For a role denial, for example,
 *              `resource_type`, `resource_id`, and `action` are all `null`
 *              because role slugs do not encode a resource or an action.
 *
 *              The outer `array_filter` in `buildEventData()` runs on the
 *              top level of the wrapping array only; it does not descend
 *              into the nested `authorization` value. The keys with null
 *              values were therefore persisting as `null` inside
 *              `security_events.event_data` rather than being omitted.
 *
 *              The persistence contract — established by
 *              `AuthorizationFailureEventTest::denied_event_data_infers_action_and_resource_from_role_denial_as_null`
 *              — requires those keys to be ABSENT rather than present with
 *              a null value. Filtering the nested payload closes that gap.
 *
 *              Impact on the persisted shape:
 *
 *                · Permission denial with inferred action and resource
 *                  (e.g. `finance.transaction.create`): all seven keys
 *                  survive with meaningful values; `application` and
 *                  `resource_id` may be absent if unset.
 *
 *                · Role denial (e.g. `super_admin`): only
 *                  `check_type`, `check_value`, and `occurred_at` survive;
 *                  `action` and `resource_type` are absent; `application`
 *                  and `resource_id` are absent unless they were set.
 *
 *              No other part of the persisted row is affected. The top-level
 *              `user_id`, `tenant_id`, `request_id`, `ip`, and `user_agent`
 *              entries are produced by the same `array_filter` that always
 *              ran on the wrapping array and continue to behave exactly as
 *              they did in v1.0.0.
 *
 *          No change to: the Responsibilities section, the Severity Policy,
 *          the synchronous-not-queued decision, the Registration mechanism,
 *          or any method signature.
 *
 * =============================================================================
 * Responsibilities
 * =============================================================================
 *   1. Map the event to a `security_events.event_type` value
 *      (`authorization.denied`).
 *   2. Compute an appropriate severity level for the failure.
 *   3. Persist the event with non-sensitive context only — never credentials,
 *      tokens, or request bodies.
 *   4. Never break the request lifecycle: any failure inside the listener
 *      is caught and logged at the application level.
 *
 * =============================================================================
 * Severity Policy
 * =============================================================================
 *   - Denial of a system-level role (e.g. `super_admin`, `platform_admin`)
 *     is treated as `critical` because it usually indicates a privilege
 *     escalation attempt or a misconfiguration.
 *   - Every other denial (permission, role, application access, or a
 *     domain-specific check) is treated as `warning`. These denials happen
 *     routinely (e.g. a user exploring a URL they don't have access to) and
 *     are useful for anomaly detection but not for immediate alerting.
 *
 * =============================================================================
 * Why synchronous (not queued)?
 * =============================================================================
 *   Security-relevant denials are low-volume and high-value: we want them
 *   persisted before the response is returned, so the audit trail is
 *   complete even if the process crashes shortly after. Queuing would
 *   introduce a failure mode where the denial is silently lost.
 *
 * =============================================================================
 * Registration
 * =============================================================================
 *   This listener is NOT auto-discovered because it lives outside the
 *   application's default `app/Listeners` directory. It is registered
 *   explicitly in `CoreServiceProvider::boot()`:
 *
 *       Event::listen(
 *           AuthorizationFailureEvent::class,
 *           LogAuthorizationFailureListener::class
 *       );
 *
 * @package MaaleArna\MASES\Core\Listeners
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
        if (!config('mases.features.audit_enabled', true)) {
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