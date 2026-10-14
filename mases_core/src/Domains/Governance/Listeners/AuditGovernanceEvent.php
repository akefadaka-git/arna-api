<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 29 of 31
 * =============================================================================
 *
 * File:            Listeners/AuditGovernanceEvent.php  (AMEND)
 * Unit:            G2c — GovernanceAppointment and GovernanceMembership
 *                  vertical slices (audit listener extension)
 * Delivery type:   Amendment
 * Net lines:       +4 event imports; +2 model imports;
 *                  +4 `match (true)` arms;
 *                  +4 handler methods
 *                    (handleGovernanceAppointmentRegistered,
 *                     handleGovernanceAppointmentUpdated,
 *                     handleGovernanceMembershipRegistered,
 *                     handleGovernanceMembershipUpdated);
 *                  +2 snapshot helpers
 *                    (appointmentSnapshot, membershipSnapshot);
 *                  +~90 docblock lines rewritten in the class docblock
 *                     §"Events handled" and §"Action slug vocabulary";
 *                  +~40 docblock lines added to §"old_values / new_values"
 *                     covering the two new entities;
 *                  +~280 lines for this change-verification block.
 *
 * Before (as G2b file 25 of 27 delivered it):
 *   The listener handled exactly six Governance-domain events:
 *
 *     GovernanceBodyRegistered       →  governance.body.registered
 *     GovernanceBodyUpdated          →  governance.body.updated
 *     GovernancePositionRegistered   →  governance.position.registered
 *     GovernancePositionUpdated      →  governance.position.updated
 *     GovernanceTermRegistered       →  governance.term.registered
 *     GovernanceTermUpdated          →  governance.term.updated
 *
 *   Its `match (true)` expression had six arms plus a `default`.
 *   Its three snapshot helpers captured a `GovernanceBody`, a
 *   `GovernancePosition`, and a `GovernanceTerm`. Its class docblock
 *   recorded six events in §"Events handled" and six action slugs
 *   in §"Action slug vocabulary".
 *
 *   The four events that G2c introduced — two for appointments
 *   (files 14, 15 of 31) and two for memberships (files 27, 28 of
 *   31) — were constructed and dispatched by their respective
 *   services, but had no listener branch to receive them. The
 *   events were built, dispatched into a dispatcher with a
 *   subscriber registered only for the six pre-existing events, and
 *   discarded without leaving a trace in `audit_logs`.
 *
 *   The consequence: every appointment and membership registration
 *   or update — including the six membership lifecycle transitions
 *   (end, suspend, resume, resign, remove, expire) — was silently
 *   unrecorded in the audit trail. The G2c feature tests (files 13
 *   and 26 of 31) that assert audit rows exist for these operations
 *   would fail with clear assertion failures.
 *
 * After:
 *   Four new event imports and two new model imports are added at
 *   the top of the file. Four new `match (true)` arms are added to
 *   the `handle()` method. Four new handler methods are added, each
 *   structured identically to their existing counterparts. Two new
 *   snapshot helpers are added.
 *
 *   The listener now handles ten events:
 *
 *     GovernanceBodyRegistered          →  governance.body.registered
 *     GovernanceBodyUpdated             →  governance.body.updated
 *     GovernancePositionRegistered      →  governance.position.registered
 *     GovernancePositionUpdated         →  governance.position.updated
 *     GovernanceTermRegistered          →  governance.term.registered
 *     GovernanceTermUpdated             →  governance.term.updated
 *     GovernanceAppointmentRegistered   →  governance.appointment.registered
 *     GovernanceAppointmentUpdated      →  governance.appointment.updated
 *     GovernanceMembershipRegistered    →  governance.membership.registered
 *     GovernanceMembershipUpdated       →  governance.membership.updated
 *
 *   The four new handlers are structurally identical to their
 *   six existing counterparts:
 *
 *     · Registered handlers build a full post-creation snapshot
 *       via the appropriate `{noun}Snapshot()` helper and write it
 *       to `new_values`. `old_values` is null.
 *
 *     · Updated handlers derive the change set from the model's
 *       `getChanges()` and `getOriginal()` methods, filtering the
 *       original snapshot to the keys that changed.
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · The six existing event handler methods — every line is
 *     byte-for-byte identical to the G2b version.
 *
 *   · The three existing snapshot helpers (`bodySnapshot`,
 *     `positionSnapshot`, `termSnapshot`) — every line is
 *     byte-for-byte identical.
 *
 *   · The `resolveRequestId()` method — every line is byte-for-byte
 *     identical.
 *
 *   · The fail-open `try`/`catch (Throwable)` wrapper around the
 *     entire `handle()` body.
 *
 *   · The constructor and its promoted `AuditService` property.
 *
 *   · The class declaration, the `final` modifier.
 *
 * =============================================================================
 * The four new action slugs
 * =============================================================================
 * The two appointment slugs and two membership slugs follow the
 * same `governance.{noun}.{action}` pattern the six existing slugs
 * established:
 *
 *   governance.appointment.registered
 *   governance.appointment.updated
 *   governance.membership.registered
 *   governance.membership.updated
 *
 * The slugs are English identifiers used by audit reviewers and
 * reporting tools. They are NOT user-facing strings. The vocabulary
 * is deliberately stable; changing a slug would break historical
 * audit queries.
 *
 * =============================================================================
 * The four lifecycle transitions of the membership service — why
 * they emit the `updated` slug
 * =============================================================================
 * The membership service dispatches `GovernanceMembershipUpdated`
 * from seven methods: `update()`, `end()`, `suspend()`, `resume()`,
 * `resign()`, `remove()`, and `expire()`. Each of the six lifecycle
 * methods transitions the row's `status` to one of the six
 * `GovernanceMembershipStatus` values.
 *
 * The listener does NOT distinguish these transitions at the
 * action-slug level. Every one of them produces
 * `governance.membership.updated`. The diff — which is recorded in
 * the audit row's `old_values` and `new_values` — carries the
 * `status` change from its pre-transition value to its
 * post-transition value.
 *
 * A future CR that decides the audit trail should distinguish the
 * transitions would either:
 *
 *   · Derive a finer slug from the change set (e.g. read
 *     `getChanges()['status']` and emit
 *     `governance.membership.ended`,
 *     `governance.membership.resigned`, etc.), OR
 *
 *   · Split the `GovernanceMembershipUpdated` event into six
 *     distinct event classes, each with its own handler branch.
 *
 * Both approaches are CR-worthy changes: they alter the audit
 * trail's vocabulary and would require a coordinated update to any
 * reporting that consumes the current slugs. The current design
 * keeps the slug vocabulary stable and delegates the discrimination
 * to consumers that read the diff.
 *
 * =============================================================================
 * The two new snapshot helpers
 * =============================================================================
 * Each new snapshot helper explicitly lists the columns to record
 * in the audit row. The explicit list is deliberate — a future
 * column added to `governance_appointments` or
 * `governance_memberships` does NOT silently appear in the audit
 * trail. The CR that adds an audit-relevant column also amends the
 * corresponding snapshot helper.
 *
 * `appointmentSnapshot()` records:
 *
 *     tenant_id, governance_body_id, governance_position_id,
 *     governance_term_id, instrument_type, instrument_reference,
 *     instrument_date (as YYYY-MM-DD string), effective_date
 *     (as YYYY-MM-DD string), appointment_mode, status,
 *     title_am, title_en, notes_am, notes_en
 *
 * `membershipSnapshot()` records:
 *
 *     tenant_id, governance_body_id, governance_position_id,
 *     governance_term_id, governance_appointment_id, user_id,
 *     member_name_am, member_name_en, external_reference,
 *     start_date (as YYYY-MM-DD string), end_date
 *     (as YYYY-MM-DD string), status, is_ex_officio, ended_reason,
 *     notes_am, notes_en
 *
 * The date columns in both snapshots call `?->toDateString()` on
 * the Carbon instances, producing canonical date strings in the
 * audit row rather than serialized Carbon objects. This matches
 * the convention the three existing snapshot helpers use for their
 * own date columns.
 *
 * The membership snapshot is deliberately NOT derived from the
 * model's `memberKey()` helper. The helper produces a single
 * composite string; the audit trail records the four raw columns
 * so a reviewer can see WHICH identity column was used for the
 * membership. A future reader who is tempted to slim the snapshot
 * to the composite key should read this note first.
 *
 * =============================================================================
 * Why the amendment is a full-file replacement
 * =============================================================================
 * The delivery discipline requires one complete file per delivery.
 * The G2b listener is ~700 lines including docblocks; the G2c
 * amendment adds roughly 200 lines of code and 130 lines of
 * docblock. Rather than deliver a diff, this delivery ships the
 * entire amended file so the operator uploads a single file that is
 * unambiguously the new state.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Listeners/AuditGovernanceEvent.php
 *
 * This file REPLACES the G2b version.
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *
 * The listener does not need a provider amendment of its own — it
 * is already registered for the six pre-existing events by the G2b
 * provider amendment. The four new events reach it via the four
 * new `Event::listen(...)` registrations added by the G2c provider
 * amendment (file 30 of 31).
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Event imports added:       4 (GovernanceAppointmentRegistered,
 *                               GovernanceAppointmentUpdated,
 *                               GovernanceMembershipRegistered,
 *                               GovernanceMembershipUpdated)
 * Model imports added:       2 (GovernanceAppointment,
 *                               GovernanceMembership)
 * Handler methods added:     4
 * Snapshot helpers added:    2 (appointmentSnapshot,
 *                               membershipSnapshot)
 * `match` arms added:        4
 * Existing handlers changed: 0 (preserved byte-for-byte)
 * =============================================================================
 */

/*
 * =============================================================================
 * A3 CANDIDATE — NOT APPLIED — NOT TESTED
 * =============================================================================
 *
 * RP-01 — audit before/after integrity (listener side).
 *
 * Relative to the G2c v1.2.0 file above, this A3 candidate makes exactly
 * two executable changes, both in the two update handlers that derive
 * `old_values` from a mutated model:
 *
 *     handleGovernanceAppointmentUpdated()
 *     handleGovernanceMembershipUpdated()
 *
 * In each of the two handlers, the pre-change originals are now sourced
 * preferentially from the event's `$beforeSnapshot` property, with a
 * fallback to the model's own `getOriginal()` for backward compatibility:
 *
 *     // Before (G2c v1.2.0):
 *     $original = $appointment->getOriginal();
 *     $original = $membership->getOriginal();
 *
 *     // After (A3):
 *     $original = $event->beforeSnapshot ?? $appointment->getOriginal();
 *     $original = $event->beforeSnapshot ?? $membership->getOriginal();
 *
 * WHY THIS IS REQUIRED
 * -----------------------------------------------------------------------------
 * In the pre-A3 behaviour, the dispatching services (`GovernanceAppointmentService`
 * and `GovernanceMembershipService`) mutated and saved the model, dispatched
 * the corresponding `*Updated` event, and then called `$model->refresh()`.
 * Laravel's `finishSave()` (called by `save()`) and `refresh()` both call
 * `syncOriginal()`, which synchronises the model's original attributes to
 * the reloaded persisted state.
 *
 * By the time this listener's `handle()` body runs (inside the
 * `DB::afterCommit()` callback), the model instance carried on the event
 * has originals that already reflect the POST-change state. The
 * `getOriginal()`-derived `$oldValues` filter therefore produced the same
 * values as `getChanges()`, and the audit row's `old_values` equalled its
 * `new_values` on every changed key. The audit trail could not distinguish
 * before from after.
 *
 * The fix captures the pre-change attribute map in the service, immediately
 * before the mutation, and conveys it through the event as `$beforeSnapshot`.
 * This listener now prefers that snapshot when present.
 *
 * THE COMPANION SERVICE CHANGES
 * -----------------------------------------------------------------------------
 * The two services that dispatch the two events this listener handles are
 * changed in the same A3 batch:
 *
 *   · `Services/GovernanceAppointmentService.php` — `update()`, `activate()`,
 *     `revoke()`, `supersede()` each capture `$beforeSnapshot` before the
 *     mutation and pass it to `new GovernanceAppointmentUpdated(...)`.
 *
 *   · `Services/GovernanceMembershipService.php` — `update()` and
 *     `transitionTo()` (which covers all six lifecycle methods) each
 *     capture `$beforeSnapshot` before the mutation and pass it to
 *     `new GovernanceMembershipUpdated(...)`.
 *
 * The two event classes are also changed in the same A3 batch to accept
 * the new constructor parameter with a default of `null`:
 *
 *   · `Events/GovernanceAppointmentUpdated.php`
 *   · `Events/GovernanceMembershipUpdated.php`
 *
 * WHY THE FALLBACK IS PRESERVED
 * -----------------------------------------------------------------------------
 * The `?? $model->getOriginal()` fallback is deliberate and serves three
 * purposes:
 *
 *   · It preserves backward compatibility for any pre-A3 caller (tests,
 *     diagnostic scripts, queued jobs) that constructs the event with a
 *     single argument. Such a caller receives the pre-A3 behaviour — the
 *     defect — but does not get a TypeError.
 *
 *   · It keeps the listener tolerant of a deployment order in which the
 *     two event classes are not yet on disk with the new constructor
 *     parameter. A single-argument event still produces a valid audit row,
 *     albeit with the pre-A3 defect for that call site.
 *
 *   · It makes the listener the sole place the distinction is resolved.
 *     A future CR that removes the default from the event constructors and
 *     makes `$beforeSnapshot` required would also remove this fallback in
 *     the same change.
 *
 * WHAT DOES NOT CHANGE
 * -----------------------------------------------------------------------------
 *   · The class name, namespace, and file path.
 *   · The `final` modifier and the `AuditService` dependency.
 *   · The fail-open `try`/`catch (Throwable)` wrapper in `handle()`.
 *   · The `match (true)` expression and all ten arms.
 *   · The two registered handlers — `handleGovernanceAppointmentRegistered()`
 *     and `handleGovernanceMembershipRegistered()` — are not touched;
 *     registration is a creation and the pre-A3 behaviour was already
 *     correct there (`old_values: null` and a post-create snapshot).
 *   · The six pre-existing handlers for body, position, and term.
 *   · The three pre-existing snapshot helpers (`bodySnapshot`,
 *     `positionSnapshot`, `termSnapshot`).
 *   · The two G2c snapshot helpers (`appointmentSnapshot`,
 *     `membershipSnapshot`).
 *   · The `resolveRequestId()` method.
 *   · Every action slug.
 *   · The `AuditService::log()` call signature and argument names.
 *
 * STALE DOCBLOCK — DELIBERATELY NOT REWRITTEN
 * -----------------------------------------------------------------------------
 * The class docblock's §"old_values / new_values" section still describes
 * the pre-A3 mechanism — "the subset of `getOriginal()` corresponding to
 * keys present in `getChanges()`". After this A3 change, the source of
 * `$original` in the two update handlers is the event's `$beforeSnapshot`
 * when present, and `getOriginal()` only as a fallback.
 *
 * The section is preserved verbatim in the class docblock above per the
 * A3 mandate to preserve existing documentation. This block is the
 * authoritative note recording that the section is now superseded for the
 * appointment and membership update paths. A future documentation-only CR
 * may rewrite §"old_values / new_values" to describe the new sourcing.
 *
 * COMPANION FILES IN THIS A3 BATCH
 * -----------------------------------------------------------------------------
 *   · Events/GovernanceAppointmentUpdated.php       (delivered)
 *   · Services/GovernanceAppointmentService.php     (delivered)
 *   · Events/GovernanceMembershipUpdated.php        (delivered)
 *   · Services/GovernanceMembershipService.php      (delivered)
 *   · Listeners/AuditGovernanceEvent.php            (this file)
 *   · Models/GovernanceAppointment.php              (pending — RP-02)
 *   · Models/GovernanceMembership.php               (pending — RP-02)
 *
 * VERIFICATION
 * -----------------------------------------------------------------------------
 * A3 does not permit test execution. The applicable test plan is
 * recorded in the A3 response's §5.1 through §5.4. In particular:
 *
 *   · §5.1 (appointment update) — asserts `old_values['title_en']`
 *     equals the pre-change value.
 *   · §5.2 (appointment lifecycle) — asserts `old_values['status']`
 *     equals the pre-transition status.
 *   · §5.3 (membership update) — asserts `old_values['member_name_en']`
 *     equals the pre-change value.
 *   · §5.4 (membership lifecycle) — asserts `old_values['status']`
 *     equals the pre-transition status for all six transitions.
 *
 * No test was run.
 *
 * ROLLBACK
 * -----------------------------------------------------------------------------
 * Revert this file, the two services, and the two event classes. No schema
 * change, no migration, no data rewrite. Existing audit rows are
 * unaffected; the pre-A3 rows retain their pre-A3 shape (which exhibited
 * the defect).
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Listeners
 * @version 1.3.0-a3-candidate
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Listeners;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceAppointmentRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceAppointmentUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceBodyRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceBodyUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceMembershipRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceMembershipUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernancePositionRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernancePositionUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceTermRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceTermUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use Throwable;

/**
 * AuditGovernanceEvent
 *
 * Sole listener for the Governance domain's events. Writes a row to
 * the platform `audit_logs` table via the Core `AuditService`.
 *
 * See the file-level change-verification block for the full rationale
 * on the listener's responsibilities, its fail-open semantics, its
 * snapshot helpers, its request-id resolution chain, and its growth
 * path as the domain expands.
 *
 * See the A3 CANDIDATE block above the namespace for the RP-01
 * pre-change snapshot preference introduced in this revision.
 *
 * =============================================================================
 * Events handled
 * =============================================================================
 * Ten events across five entities. The two body events were added
 * at G2a; the two position events and two term events at G2b; the
 * two appointment events and two membership events at G2c.
 *
 *   Entity                  Event                              Action slug
 *   ─────────────────────   ────────────────────────────────   ─────────────────────────────────
 *   GovernanceBody          GovernanceBodyRegistered           governance.body.registered
 *   GovernanceBody          GovernanceBodyUpdated              governance.body.updated
 *   GovernancePosition      GovernancePositionRegistered       governance.position.registered
 *   GovernancePosition      GovernancePositionUpdated          governance.position.updated
 *   GovernanceTerm          GovernanceTermRegistered           governance.term.registered
 *   GovernanceTerm          GovernanceTermUpdated              governance.term.updated
 *   GovernanceAppointment   GovernanceAppointmentRegistered    governance.appointment.registered
 *   GovernanceAppointment   GovernanceAppointmentUpdated       governance.appointment.updated
 *   GovernanceMembership    GovernanceMembershipRegistered     governance.membership.registered
 *   GovernanceMembership    GovernanceMembershipUpdated        governance.membership.updated
 *
 * All ten events are dispatched by their respective services inside
 * `DB::afterCommit(...)` callbacks, so this listener runs only after
 * the corresponding transaction has committed.
 *
 * =============================================================================
 * Action slug vocabulary
 * =============================================================================
 * Each event maps to a distinct action slug. The slugs are English
 * identifiers used by audit reviewers and reporting tools; they are
 * NOT user-facing strings. The vocabulary is deliberately stable:
 *
 *   governance.body.registered
 *   governance.body.updated
 *   governance.position.registered
 *   governance.position.updated
 *   governance.term.registered
 *   governance.term.updated
 *   governance.appointment.registered
 *   governance.appointment.updated
 *   governance.membership.registered
 *   governance.membership.updated
 *
 * Changing a slug would break historical audit queries and is a
 * CR-worthy change.
 *
 * =============================================================================
 * old_values / new_values
 * =============================================================================
 * For creation-like events (registration), `old_values` is null and
 * `new_values` is a snapshot of the newly-created resource's key
 * fields.
 *
 * For updates, both `old_values` and `new_values` are populated from
 * the model's change tracking:
 *
 *   old_values  —  the subset of `getOriginal()` corresponding to
 *                  keys present in `getChanges()`.
 *   new_values  —  `getChanges()`, the exact set of attributes that
 *                  differed before and after the update.
 *
 * The change-set approach means an update that touches only the
 * `status` flag produces an audit row whose `new_values` contains
 * only `status` (and possibly `updated_at`). This keeps the audit
 * trail precise and avoids recording unchanged columns as if they
 * had moved.
 *
 * The six membership lifecycle methods (`end`, `suspend`, `resume`,
 * `resign`, `remove`, `expire`) all produce audit rows under the
 * `governance.membership.updated` slug. The distinguishing
 * information — which transition ran — is carried by the change
 * set's `status` key. See the file-level change-verification block
 * §"The four lifecycle transitions of the membership service".
 *
 * NOTE (A3 / RP-01): for the appointment and membership update
 * paths, `old_values` is now sourced from the event's
 * `$beforeSnapshot` when present, and from `getOriginal()` only as
 * a fallback. See the A3 CANDIDATE block above the namespace. This
 * paragraph is preserved verbatim per the A3 documentation-preservation
 * mandate; the A3 block is authoritative for the amended behaviour.
 *
 * =============================================================================
 * Tenant resolution
 * =============================================================================
 * Each event's tenant context is derived from the affected resource:
 *
 *   GovernanceBodyRegistered          →  body.tenant_id
 *   GovernanceBodyUpdated             →  body.tenant_id
 *   GovernancePositionRegistered      →  position.tenant_id
 *   GovernancePositionUpdated         →  position.tenant_id
 *   GovernanceTermRegistered          →  term.tenant_id
 *   GovernanceTermUpdated             →  term.tenant_id
 *   GovernanceAppointmentRegistered   →  appointment.tenant_id
 *   GovernanceAppointmentUpdated      →  appointment.tenant_id
 *   GovernanceMembershipRegistered    →  membership.tenant_id
 *   GovernanceMembershipUpdated       →  membership.tenant_id
 *
 * All ten events carry their model with the `tenant_id` column
 * already set (the services set it explicitly on register and the
 * model retains it on update), so no relation traversal is required
 * to resolve the tenant id.
 *
 * `AuditService` is passed a concrete `$tenantId` in every call, so
 * it does not fall back to the request's tenant context.
 *
 * =============================================================================
 * Actor resolution
 * =============================================================================
 * The events do not carry the actor. The listener reads `auth()->id()`
 * at dispatch time. In an HTTP request this is the authenticated
 * caller; in an Artisan command or a test with no authenticated user
 * it is null, and the audit row is attributed to the system.
 *
 * =============================================================================
 * Fail-open semantics
 * =============================================================================
 * Every `handle()` call is wrapped in `try` / `catch (Throwable)`.
 * Any exception raised while writing the audit row is caught, logged
 * via `Log::error`, and swallowed. The domain operation that
 * triggered the event has already committed — the caller has
 * already received (or will imminently receive) a successful
 * response. An audit-write failure must not turn a successful
 * business operation into a 500.
 *
 * This is the same fail-open policy used by every audit listener in
 * the platform.
 *
 * =============================================================================
 * No database queries beyond what the events require
 * =============================================================================
 * The listener performs no direct DB queries. It reads attributes
 * from the model instances carried by the events.
 *
 * =============================================================================
 * Amharic-first
 * =============================================================================
 * The listener emits no user-facing strings. Action slugs and field
 * keys are English identifiers used by audit tooling. The values it
 * records are raw column values — bilingual name and label fields
 * are stored under their own column names (`name_am`, `name_en`,
 * `label_am`, `label_en`, `member_name_am`, `member_name_en`,
 * `title_am`, `title_en`, `notes_am`, `notes_en`) so the audit
 * trail preserves both languages.
 *
 * =============================================================================
 * Growth path — the listener gains branches as the domain grows
 * =============================================================================
 * At G2c, the listener handles ten events. As the domain grows
 * through G5 and onward, the listener gains one branch per new event
 * class. The expected growth path:
 *
 *   G5:   GovernanceMeetingScheduled, GovernanceMeetingCompleted
 *   G6:   GovernanceAgendaPublished
 *   G7:   GovernanceMinuteApproved
 *   G8:   GovernanceResolutionAdopted, GovernanceActionItemAssigned,
 *         GovernanceActionItemVerified
 *   G9:   GovernanceDelegationActive, GovernanceDeclarationReviewed
 *   G10:  GovernanceElectionCertified
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Listeners
 * @version 1.3.0-a3-candidate
 * =============================================================================
 */
final class AuditGovernanceEvent
{
    /**
     * Construct the listener with its audit dependency.
     *
     * `AuditService` is a Phase 1 singleton registered by
     * `CoreServiceProvider`. The container resolves it when the
     * listener is instantiated by the event dispatcher.
     *
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly AuditService $auditService,
    ) {
    }

    // =========================================================================
    // Entry point
    // =========================================================================

    /**
     * Handle a dispatched domain event.
     *
     * Branches on the concrete event class. Unknown event types are
     * silently ignored — Laravel will not dispatch a class that was
     * not registered, so the `default` arm is purely defensive.
     *
     * Every call is wrapped in `try`/`catch (Throwable)`. See the
     * class docblock §"Fail-open semantics" for the reasoning.
     *
     * @param  object $event
     * @return void
     */
    public function handle(object $event): void
    {
        try {
            match (true) {
                // G2a — body events.
                $event instanceof GovernanceBodyRegistered        => $this->handleGovernanceBodyRegistered($event),
                $event instanceof GovernanceBodyUpdated           => $this->handleGovernanceBodyUpdated($event),

                // G2b — position events.
                $event instanceof GovernancePositionRegistered    => $this->handleGovernancePositionRegistered($event),
                $event instanceof GovernancePositionUpdated       => $this->handleGovernancePositionUpdated($event),

                // G2b — term events.
                $event instanceof GovernanceTermRegistered        => $this->handleGovernanceTermRegistered($event),
                $event instanceof GovernanceTermUpdated           => $this->handleGovernanceTermUpdated($event),

                // G2c — appointment events.
                $event instanceof GovernanceAppointmentRegistered => $this->handleGovernanceAppointmentRegistered($event),
                $event instanceof GovernanceAppointmentUpdated    => $this->handleGovernanceAppointmentUpdated($event),

                // G2c — membership events.
                $event instanceof GovernanceMembershipRegistered  => $this->handleGovernanceMembershipRegistered($event),
                $event instanceof GovernanceMembershipUpdated     => $this->handleGovernanceMembershipUpdated($event),

                default                                            => null,
            };
        } catch (Throwable $e) {
            // Fail-open. The domain transaction has already committed.
            // Record the failure in the application log; do not re-throw.
            Log::error('AuditGovernanceEvent listener failed', [
                'event_class' => $event::class,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Body event handlers (G2a — preserved byte-for-byte)
    // =========================================================================

    /**
     * Record a governance body registration.
     *
     * @param  GovernanceBodyRegistered $event
     * @return void
     */
    private function handleGovernanceBodyRegistered(GovernanceBodyRegistered $event): void
    {
        $body = $event->body;

        $this->auditService->log(
            action: 'governance.body.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceBodyRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $body->tenant_id,
            resourceType: 'GovernanceBody',
            resourceId: (string) $body->id,
            oldValues: null,
            newValues: $this->bodySnapshot($body),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance body update.
     *
     * @param  GovernanceBodyUpdated $event
     * @return void
     */
    private function handleGovernanceBodyUpdated(GovernanceBodyUpdated $event): void
    {
        $body = $event->body;

        $changes  = $event->changes;
        $original = $event->before;

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.body.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceBodyUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $body->tenant_id,
            resourceType: 'GovernanceBody',
            resourceId: (string) $body->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Position event handlers (G2b — preserved byte-for-byte)
    // =========================================================================

    /**
     * Record a governance position registration.
     *
     * @param  GovernancePositionRegistered $event
     * @return void
     */
    private function handleGovernancePositionRegistered(GovernancePositionRegistered $event): void
    {
        $position = $event->position;

        $this->auditService->log(
            action: 'governance.position.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernancePositionRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $position->tenant_id,
            resourceType: 'GovernancePosition',
            resourceId: (string) $position->id,
            oldValues: null,
            newValues: $this->positionSnapshot($position),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance position update.
     *
     * @param  GovernancePositionUpdated $event
     * @return void
     */
    private function handleGovernancePositionUpdated(GovernancePositionUpdated $event): void
    {
        $position = $event->position;

        $changes  = $event->changes;
        $original = $event->before;

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.position.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernancePositionUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $position->tenant_id,
            resourceType: 'GovernancePosition',
            resourceId: (string) $position->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Term event handlers (G2b — preserved byte-for-byte)
    // =========================================================================

    /**
     * Record a governance term registration.
     *
     * @param  GovernanceTermRegistered $event
     * @return void
     */
    private function handleGovernanceTermRegistered(GovernanceTermRegistered $event): void
    {
        $term = $event->term;

        $this->auditService->log(
            action: 'governance.term.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceTermRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $term->tenant_id,
            resourceType: 'GovernanceTerm',
            resourceId: (string) $term->id,
            oldValues: null,
            newValues: $this->termSnapshot($term),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance term update.
     *
     * @param  GovernanceTermUpdated $event
     * @return void
     */
    private function handleGovernanceTermUpdated(GovernanceTermUpdated $event): void
    {
        $term = $event->term;

        $changes  = $event->changes;
        $original = $event->before;

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.term.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceTermUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $term->tenant_id,
            resourceType: 'GovernanceTerm',
            resourceId: (string) $term->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Appointment event handlers (G2c — new)
    // =========================================================================

    /**
     * Record a governance appointment registration.
     *
     * @param  GovernanceAppointmentRegistered $event
     * @return void
     */
    private function handleGovernanceAppointmentRegistered(GovernanceAppointmentRegistered $event): void
    {
        $appointment = $event->appointment;

        $this->auditService->log(
            action: 'governance.appointment.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceAppointmentRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $appointment->tenant_id,
            resourceType: 'GovernanceAppointment',
            resourceId: (string) $appointment->id,
            oldValues: null,
            newValues: $this->appointmentSnapshot($appointment),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance appointment update.
     *
     * The event is dispatched by the service from four methods —
     * `update()`, `activate()`, `revoke()`, and `supersede()` — all
     * under the single `governance.appointment.updated` action slug.
     * The distinguishing information is carried by the change set's
     * `status` key when the update was a lifecycle transition.
     *
     * A3 / RP-01: `old_values` is now sourced from the event's
     * `$beforeSnapshot` when present, and from the model's
     * `getOriginal()` only as a fallback for backward compatibility.
     * See the A3 CANDIDATE block above the namespace.
     *
     * @param  GovernanceAppointmentUpdated $event
     * @return void
     */
    private function handleGovernanceAppointmentUpdated(GovernanceAppointmentUpdated $event): void
    {
        $appointment = $event->appointment;

        $changes = $appointment->getChanges();

        // A3 / RP-01 — prefer the pre-change snapshot captured by the
        // dispatching service before the mutation. Fall back to the
        // model's originals for legacy events constructed without a
        // snapshot (defensive; no current call site does so).
        $original = $event->beforeSnapshot ?? $appointment->getOriginal();

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.appointment.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceAppointmentUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $appointment->tenant_id,
            resourceType: 'GovernanceAppointment',
            resourceId: (string) $appointment->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Membership event handlers (G2c — new)
    // =========================================================================

    /**
     * Record a governance membership registration.
     *
     * @param  GovernanceMembershipRegistered $event
     * @return void
     */
    private function handleGovernanceMembershipRegistered(GovernanceMembershipRegistered $event): void
    {
        $membership = $event->membership;

        $this->auditService->log(
            action: 'governance.membership.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceMembershipRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $membership->tenant_id,
            resourceType: 'GovernanceMembership',
            resourceId: (string) $membership->id,
            oldValues: null,
            newValues: $this->membershipSnapshot($membership),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance membership update.
     *
     * The event is dispatched by the service from seven methods —
     * `update()`, `end()`, `suspend()`, `resume()`, `resign()`,
     * `remove()`, and `expire()` — all under the single
     * `governance.membership.updated` action slug. The distinguishing
     * information is carried by the change set's `status` key when
     * the update was a lifecycle transition. See the file-level
     * change-verification block §"The four lifecycle transitions of
     * the membership service — why they emit the `updated` slug".
     *
     * A3 / RP-01: `old_values` is now sourced from the event's
     * `$beforeSnapshot` when present, and from the model's
     * `getOriginal()` only as a fallback for backward compatibility.
     * See the A3 CANDIDATE block above the namespace.
     *
     * @param  GovernanceMembershipUpdated $event
     * @return void
     */
    private function handleGovernanceMembershipUpdated(GovernanceMembershipUpdated $event): void
    {
        $membership = $event->membership;

        $changes = $membership->getChanges();

        // A3 / RP-01 — prefer the pre-change snapshot captured by the
        // dispatching service before the mutation. Fall back to the
        // model's originals for legacy events constructed without a
        // snapshot (defensive; no current call site does so).
        $original = $event->beforeSnapshot ?? $membership->getOriginal();

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.membership.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceMembershipUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $membership->tenant_id,
            resourceType: 'GovernanceMembership',
            resourceId: (string) $membership->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Snapshot helpers — G2a + G2b (preserved byte-for-byte)
    // =========================================================================

    /**
     * Build a snapshot of a governance body's full attribute set for
     * auditing.
     *
     * @param  GovernanceBody $body
     * @return array<string, mixed>
     */
    private function bodySnapshot(GovernanceBody $body): array
    {
        return [
            'tenant_id'         => $body->tenant_id,
            'code'              => $body->code,
            'body_type'         => $body->body_type,
            'name_am'           => $body->name_am,
            'name_en'           => $body->name_en,
            'parent_body_id'    => $body->parent_body_id,
            'purpose'           => $body->purpose,
            'mandate'           => $body->mandate,
            'quorum_rule_json'  => $body->quorum_rule_json,
            'meeting_frequency' => $body->meeting_frequency,
            'reporting_body_id' => $body->reporting_body_id,
            'effective_from'    => $body->effective_from?->toDateString(),
            'effective_until'   => $body->effective_until?->toDateString(),
            'is_active'         => $body->is_active,
        ];
    }

    /**
     * Build a snapshot of a governance position's full attribute set
     * for auditing.
     *
     * @param  GovernancePosition $position
     * @return array<string, mixed>
     */
    private function positionSnapshot(GovernancePosition $position): array
    {
        return [
            'tenant_id'          => $position->tenant_id,
            'governance_body_id' => $position->governance_body_id,
            'code'               => $position->code,
            'name_am'            => $position->name_am,
            'name_en'            => $position->name_en,
            'description_am'     => $position->description_am,
            'description_en'     => $position->description_en,
            'is_voting'          => $position->is_voting,
            'is_officer'         => $position->is_officer,
            'sort_order'         => $position->sort_order,
            'is_active'          => $position->is_active,
        ];
    }

    /**
     * Build a snapshot of a governance term's full attribute set for
     * auditing.
     *
     * @param  GovernanceTerm $term
     * @return array<string, mixed>
     */
    private function termSnapshot(GovernanceTerm $term): array
    {
        return [
            'tenant_id'          => $term->tenant_id,
            'governance_body_id' => $term->governance_body_id,
            'label_am'           => $term->label_am,
            'label_en'           => $term->label_en,
            'effective_from'     => $term->effective_from?->toDateString(),
            'effective_until'    => $term->effective_until?->toDateString(),
            'notes'              => $term->notes,
            'is_active'          => $term->is_active,
        ];
    }

    // =========================================================================
    // Snapshot helpers — G2c (new)
    // =========================================================================

    /**
     * Build a snapshot of a governance appointment's full attribute
     * set for auditing.
     *
     * The snapshot excludes `id` (already the audit row's
     * `resource_id`), the timestamps, and `deleted_at`. The two date
     * columns are emitted as `YYYY-MM-DD` strings via
     * `?->toDateString()`, matching the convention the three
     * existing snapshot helpers use for their own date columns.
     *
     * @param  GovernanceAppointment $appointment
     * @return array<string, mixed>
     */
    private function appointmentSnapshot(GovernanceAppointment $appointment): array
    {
        return [
            'tenant_id'              => $appointment->tenant_id,
            'governance_body_id'     => $appointment->governance_body_id,
            'governance_position_id' => $appointment->governance_position_id,
            'governance_term_id'     => $appointment->governance_term_id,
            'instrument_type'        => $appointment->instrument_type,
            'instrument_reference'   => $appointment->instrument_reference,
            'instrument_date'        => $appointment->instrument_date?->toDateString(),
            'effective_date'         => $appointment->effective_date?->toDateString(),
            'appointment_mode'       => $appointment->appointment_mode,
            'status'                 => $appointment->status,
            'title_am'               => $appointment->title_am,
            'title_en'               => $appointment->title_en,
            'notes_am'               => $appointment->notes_am,
            'notes_en'               => $appointment->notes_en,
        ];
    }

    /**
     * Build a snapshot of a governance membership's full attribute
     * set for auditing.
     *
     * The snapshot excludes `id` (already the audit row's
     * `resource_id`), the timestamps, and `deleted_at`. The two date
     * columns are emitted as `YYYY-MM-DD` strings via
     * `?->toDateString()`.
     *
     * The snapshot records all four member-identity columns
     * (`user_id`, `external_reference`, `member_name_am`,
     * `member_name_en`) as separate keys, rather than the composite
     * string the model's `memberKey()` helper produces. See the
     * file-level change-verification block §"The two new snapshot
     * helpers" for the reasoning.
     *
     * @param  GovernanceMembership $membership
     * @return array<string, mixed>
     */
    private function membershipSnapshot(GovernanceMembership $membership): array
    {
        return [
            'tenant_id'                => $membership->tenant_id,
            'governance_body_id'       => $membership->governance_body_id,
            'governance_position_id'   => $membership->governance_position_id,
            'governance_term_id'       => $membership->governance_term_id,
            'governance_appointment_id'=> $membership->governance_appointment_id,
            'user_id'                  => $membership->user_id,
            'member_name_am'           => $membership->member_name_am,
            'member_name_en'           => $membership->member_name_en,
            'external_reference'       => $membership->external_reference,
            'start_date'               => $membership->start_date?->toDateString(),
            'end_date'                 => $membership->end_date?->toDateString(),
            'status'                   => $membership->status,
            'is_ex_officio'            => $membership->is_ex_officio,
            'ended_reason'             => $membership->ended_reason,
            'notes_am'                 => $membership->notes_am,
            'notes_en'                 => $membership->notes_en,
        ];
    }

    // =========================================================================
    // Request-id resolution (G2a — preserved byte-for-byte)
    // =========================================================================

    /**
     * Resolve the request id for the current request, if available.
     *
     * Priority:
     *   1. `X-Request-ID` header (set by upstream proxies / clients).
     *   2. `X-Correlation-ID` header (alternative common name).
     *   3. A freshly generated UUID v4.
     *
     * Mirrors the resolution order used by the canonical error
     * renderer in `bootstrap/app.php` and by the platform's other
     * audit listeners.
     *
     * @return string
     */
    private function resolveRequestId(): string
    {
        $request = request();

        foreach (['X-Request-ID', 'X-Correlation-ID'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}