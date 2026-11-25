<?php

declare(strict_types=1);



/*
|--------------------------------------------------------------------------
| MASES Governance Domain — Configuration
|--------------------------------------------------------------------------
|
| This file defines the domain-level constants and defaults for
| the MASES Governance domain. Every service, model, request,
| migration and resource under
| mases_core/src/Domains/Governance/ MUST read these values from
| config('governance.*') rather than hard-coding them locally.
|
| The config is merged into the application container by
| GovernanceServiceProvider (G1 file 4 of 10) under the key
| `governance`.
|
| -----------------------------------------------------------------------------
| Amharic-first / English-second
| -----------------------------------------------------------------------------
| This file declares ENGLISH IDENTIFIERS only — the machine-readable
| values stored in the database and used by application code.
| Human-readable bilingual labels live in the domain's
| `Resources/lang/{am,en}/` catalogues, resolved at render time.
|
| This split mirrors the Shareholder domain's convention: the
| config declares `entry_types = ['subscription', 'transfer_in', ...]`
| in English; the resource layer resolves the Amharic label via
| `__('shareholder::...')`. Governance follows the same pattern.
|
| -----------------------------------------------------------------------------
| Vocabulary is closed by the master prompt, not by this file
| -----------------------------------------------------------------------------
| The body types, membership types, attendance statuses,
| action-item statuses, declaration types, and confidentiality
| levels are declared in the master prompt §8–§26. Those arrays
| are FROZEN at the values shown here. Changing any value is a
| Change Request, because it changes the meaning of historical
| rows in tables those arrays will populate.
|
| The lifecycle arrays for meetings, minutes, resolutions,
| delegations, and elections are declared here as
| FORWARD DECLARATIONS. The master prompt specifies those
| entities will have lifecycles but does not enumerate the
| states. The values below are a reasonable initial vocabulary,
| consumed by G5, G7, G8, G9, and G10 respectively. Any phase
| whose design discussion settles on different states may
| amend the corresponding array via a CR that touches this file
| and the phase's files together.
|
| -----------------------------------------------------------------------------
| Editing rules
| -----------------------------------------------------------------------------
|   - Values in this file are technical constants. Changing them
|     can break migrations, seeders and tests.
|   - Any change to a value declared here requires a Change
|     Request.
|   - Operator-tunable values (those wrapped in env()) may be
|     changed via .env without a CR.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the Governance domain. When false, the
    | GovernanceServiceProvider skips migration and route
    | registration, and refuses write operations. Translations,
    | policies, and event listeners are still loaded (mirroring
    | the Shareholder pattern) so that historical rows remain
    | renderable and the audit trail remains intact.
    |
    | Default: true — the domain is live at the scaffold level in
    | G1, even though no business entities exist yet.
    |
    */
    'enabled' => (bool) env('MASES_GOVERNANCE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix & Middleware
    |--------------------------------------------------------------------------
    |
    | The base path under which every Governance route is
    | registered by the domain's Routes/api.php (G1 file 5). The
    | default `api/governance` produces URLs such as:
    |
    |     GET  /api/governance/ping
    |     GET  /api/governance/bodies            (G2)
    |     GET  /api/governance/meetings          (G5)
    |
    | The `api/` segment is part of the prefix — it is not added
    | by the middleware. This mirrors the convention used by every
    | other domain in the platform: Shareholder at
    | `api/shareholders`, Wallet at `api/wallets`, Loan at
    | `api/loans`, Core Files at `api/files`.
    |
    | The middleware list is applied to the whole route group
    | before the provider registers it. Middleware aliases are the
    | ones registered in bootstrap/app.php — do not introduce new
    | aliases here.
    |
    | The value is env-overridable so that a deployment can mount
    | the domain's routes at a different path without editing this
    | file.
    |
    */
    'routes' => [
        'prefix'     => env('MASES_GOVERNANCE_ROUTE_PREFIX', 'api/governance'),
        'middleware' => [
            'api',
            'auth:sanctum',
            'tenant.membership',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    |
    | Domain events are written to the platform `audit_logs` table
    | via the Core AuditService. `emit_events` controls whether the
    | domain dispatches its business events at all; when false, no
    | audit row is written for the domain.
    |
    | Keep true in every environment except a controlled migration
    | window. This mirrors the Shareholder, Wallet, and Loan
    | domains' convention.
    |
    | Governance is a high-value audit surface: every material
    | mutation (body creation, appointment, minute approval,
    | resolution adoption, action verification, delegation,
    | declaration review, election certification) is required by
    | the master prompt §31 to be recorded. Setting this flag
    | false suppresses the entire trail and should require an
    | explicit operator action plus a documented reason.
    |
    */
    'audit' => [
        'emit_events' => (bool) env('MASES_GOVERNANCE_AUDIT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Governance Body Types (FROZEN — master prompt §8)
    |--------------------------------------------------------------------------
    |
    | The closed vocabulary of governance body types. Stored as a
    | VARCHAR on `governance_bodies.body_type`; never a MySQL ENUM.
    |
    |   assembly      — the shareholders' general assembly
    |   board         — the Board of Directors
    |   committee     — a standing committee of a parent body
    |   subcommittee  — a committee of a committee
    |   ad_hoc        — a temporary body for a specific purpose
    |
    | Individual committees (Procurement, Control, Election
    | Executive, …) are rows with `body_type = 'committee'`, not
    | new values in this array. Adding a new body type is a CR
    | (ADR-GOV-005).
    |
    */
    'body_types' => [
        'assembly',
        'board',
        'committee',
        'subcommittee',
        'ad_hoc',
    ],

    /*
    |--------------------------------------------------------------------------
    | Membership Types (FROZEN — master prompt §13)
    |--------------------------------------------------------------------------
    |
    | The vocabulary of how a person came to occupy a governance
    | position. Stored on `governance_memberships.membership_type`.
    |
    |   elected        — chosen by a vote of an electing body
    |   appointed      — designated by an appointing authority
    |   ex_officio     — holds the position by virtue of another role
    |   representative — acts on behalf of a shareholder or entity
    |   observer       — attends without voting rights
    |   alternate      — substitutes for another member when needed
    |   acting         — temporarily holds the position
    |   temporary      — holds the position for a bounded short period
    |
    | The `membership_type` is orthogonal to the RBAC role. A user
    | with the `board` role may have zero, one, or several
    | memberships across different bodies and terms.
    |
    */
    'membership_types' => [
        'elected',
        'appointed',
        'ex_officio',
        'representative',
        'observer',
        'alternate',
        'acting',
        'temporary',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance Statuses (FROZEN — master prompt §15)
    |--------------------------------------------------------------------------
    |
    | The vocabulary of a participant's attendance at a meeting.
    | Stored on `governance_meeting_participants.attendance_status`.
    |
    |   invited  — invited but no response recorded yet
    |   present  — attended
    |   absent   — did not attend, no notice given
    |   excused  — did not attend, notice given in advance
    |   late     — arrived after the meeting began
    |   remote   — attended via virtual means
    |
    | Proxy attendance is NOT expressed by a distinct status. If
    | proxies are permitted by the by-law, a proxy's attendance
    | is recorded as `present` on the proxy's row, with the
    | `is_proxy` boolean and `proxy_for_membership_id` foreign key
    | recording the delegation. Whether proxies are permitted at
    | all is a [BUSINESS DECISION] (G0 §12 #18) and defaults to
    | off until the by-law is available.
    |
    */
    'attendance_statuses' => [
        'invited',
        'present',
        'absent',
        'excused',
        'late',
        'remote',
    ],

    /*
    |--------------------------------------------------------------------------
    | Meeting Statuses (forward declaration — consumed by G5)
    |--------------------------------------------------------------------------
    |
    | The lifecycle of a `governance_meetings` row. Declared here
    | so that G5 does not need to amend this file to introduce its
    | own vocabulary.
    |
    |   scheduled    — the meeting has been created; not yet started
    |   in_progress  — the meeting is currently underway
    |   adjourned    — the meeting paused, may resume
    |   completed    — the meeting has concluded
    |   cancelled    — the meeting will not take place
    |
    | This is NOT a universal workflow. It is the specific state
    | vocabulary of one entity. Each entity's status vocabulary is
    | declared as a separate array (ADR-GOV-009).
    |
    */
    'meeting_statuses' => [
        'scheduled',
        'in_progress',
        'adjourned',
        'completed',
        'cancelled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Minute Approval Statuses (forward declaration — consumed by G7)
    |--------------------------------------------------------------------------
    |
    | The approval lifecycle of a `governance_minutes` row.
    |
    |   draft      — being prepared by the secretary
    |   submitted  — presented to the body for approval
    |   approved   — adopted by the body; the row becomes immutable
    |   rejected   — sent back for correction; a new version will
    |                be prepared
    |
    | Once a minute reaches `approved`, the model-layer immutability
    | guard (mirroring `LoanRestructuring`) forbids UPDATE and
    | DELETE. A correction creates a new row with a higher
    | `version` and `supersedes_id` pointing at the approved
    | original. This is enforced in G7, not here.
    |
    | Who approves minutes (the body, the chair, or the secretary)
    | is a [BUSINESS DECISION] (G0 §12 #11). It does not affect the
    | status vocabulary — only the authorization rule that
    | governs the `submitted → approved` transition.
    |
    */
    'minute_approval_statuses' => [
        'draft',
        'submitted',
        'approved',
        'rejected',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolution Statuses (forward declaration — consumed by G8)
    |--------------------------------------------------------------------------
    |
    | The lifecycle of a `governance_resolutions` row.
    |
    |   draft       — being prepared
    |   submitted   — presented to the body for a vote
    |   adopted     — passed; the row becomes immutable
    |   rejected    — did not pass
    |   superseded  — replaced by a later resolution on the same
    |                 subject; retained for the historical record
    |
    | Whether "Resolution" and "Decision" are distinct legal
    | objects or one is a [BUSINESS DECISION] (G0 §12 #10). If they
    | are one, `governance_decisions` does not exist and this array
    | is the only resolution-related vocabulary. If they are two,
    | a sibling `decision_statuses` array is added in G8.
    |
    */
    'resolution_statuses' => [
        'draft',
        'submitted',
        'adopted',
        'rejected',
        'superseded',
    ],

    /*
    |--------------------------------------------------------------------------
    | Action-Item Statuses (FROZEN — master prompt §21)
    |--------------------------------------------------------------------------
    |
    | The lifecycle of a `governance_action_items` row. Distinct
    | from other entities' lifecycles because an action has both a
    | "done" state (completed) and a "confirmed done" state
    | (verified), and verification is deliberately separate from
    | completion (master prompt §21).
    |
    |   pending    — recorded but not yet assigned
    |   assigned   — has a responsible person
    |   in_progress— work has begun
    |   completed  — responsible person reports the work is done
    |   verified   — an authorized governance member has
    |                confirmed the work (see below)
    |   overdue    — due date has passed without verification
    |   cancelled  — withdrawn by an authorized party
    |
    | The `completed → verified` transition requires a permission
    | (`governance.action.verify`) that the responsible person
    | should normally not hold for their own action. The precise
    | rule is enforced in G8's policy layer, not here.
    |
    */
    'action_item_statuses' => [
        'pending',
        'assigned',
        'in_progress',
        'completed',
        'verified',
        'overdue',
        'cancelled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delegation Statuses (forward declaration — consumed by G9)
    |--------------------------------------------------------------------------
    |
    | The lifecycle of a `governance_delegations` row.
    |
    |   draft    — being prepared
    |   active   — in force; within the effective period
    |   expired  — the effective period has ended
    |   revoked  — withdrawn by an authorized party before expiry
    |
    | Expired and revoked delegations must never grant authority.
    | Whether a delegation may be revoked at all is a governance
    | policy decision; the vocabulary supports revocation either
    | way.
    |
    */
    'delegation_statuses' => [
        'draft',
        'active',
        'expired',
        'revoked',
    ],

    /*
    |--------------------------------------------------------------------------
    | Declaration Types (FROZEN — master prompt §23)
    |--------------------------------------------------------------------------
    |
    | The vocabulary of a `governance_declarations` row.
    |
    |   conflict_of_interest — a member's personal interest may
    |                          conflict with a governance decision
    |   related_party        — a member has a disclosed relationship
    |                          with an entity the body interacts with
    |   other                — a declaration that does not fit the
    |                          two specific categories above
    |
    | Adding a new declaration type is a CR, because it changes
    | the meaning of historical rows.
    |
    */
    'declaration_types' => [
        'conflict_of_interest',
        'related_party',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Declaration Review Statuses (forward declaration — consumed by G9)
    |--------------------------------------------------------------------------
    |
    | The review lifecycle of a `governance_declarations` row.
    |
    |   pending    — received; not yet under review
    |   in_review  — under review by an authorized party
    |   reviewed   — the review is complete; a mitigation may or
    |                may not have been recorded
    |   escalated  — referred to a higher authority
    |
    | Who reviews declarations, and who may see them, is a
    | [BUSINESS DECISION] (G0 §12 #19). The confidentiality layer
    | (see `confidentiality_levels` below) applies on top of RBAC,
    | independent of this status.
    |
    */
    'declaration_review_statuses' => [
        'pending',
        'in_review',
        'reviewed',
        'escalated',
    ],

    /*
    |--------------------------------------------------------------------------
    | Election Statuses (forward declaration — consumed by G10)
    |--------------------------------------------------------------------------
    |
    | The lifecycle of a `governance_elections` row.
    |
    |   planned    — the election has been scheduled; nominations
    |                have not yet opened
    |   nominating — the nomination window is open
    |   voting     — the voting window is open
    |   counting   — voting has closed; results are being tallied
    |   certified  — results have been certified; the row becomes
    |                immutable
    |   cancelled  — the election will not take place
    |
    | This vocabulary describes the ADMINISTRATIVE record of an
    | election. It does NOT describe electronic voting — that is
    | deliberately excluded by ADR-GOV-010 and by the
    | `features.electronic_voting_enabled` flag below.
    |
    | Who certifies election results is a [BUSINESS DECISION]
    | (G0 §12 #23). It does not affect the vocabulary — only the
    | authorization rule that governs the `counting → certified`
    | transition.
    |
    */
    'election_statuses' => [
        'planned',
        'nominating',
        'voting',
        'counting',
        'certified',
        'cancelled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidentiality Levels (FROZEN — master prompt §26)
    |--------------------------------------------------------------------------
    |
    | The four confidentiality classifications that a governance
    | record may carry. Ordered from least to most sensitive.
    |
    |   public        — may be disclosed to any authenticated user
    |                   in the tenant
    |   restricted    — visible to any member of the owning body
    |   confidential  — visible to the body's officers and to
    |                   explicitly authorized users
    |   secret        — visible only to explicitly authorized users,
    |                   typically the chair, and audited on read
    |
    | A confidentiality level is an AUTHORIZATION layer that
    | applies ON TOP of RBAC, not a replacement for it. A user
    | must satisfy BOTH:
    |   · the RBAC permission (e.g. `governance.minute.view`), AND
    |   · the confidentiality check (are they authorized for this
    |     specific record at this level?)
    |
    | The precise audience for each level is a [BUSINESS DECISION]
    | (G0 §12 #13). The four levels themselves are frozen; who may
    | see a `secret` minute is not.
    |
    */
    'confidentiality_levels' => [
        'public',
        'restricted',
        'confidential',
        'secret',
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Categories (FROZEN — master prompt §25)
    |--------------------------------------------------------------------------
    |
    | The categories of governance documents that may be linked to
    | a governance record via `governance_document_links`. Stored
    | as a VARCHAR on `governance_document_links.document_category`.
    |
    |   meeting_notice       — the invitation to a meeting
    |   agenda               — the meeting's agenda
    |   minutes              — the approved minutes
    |   resolution           — a resolution's text or signed copy
    |   appointment_letter   — the instrument creating a membership
    |   committee_mandate    — a committee's charter or mandate
    |   election_record      — an election's certified record
    |   declaration          — a declaration's supporting document
    |   action_evidence      — evidence attached to an action item
    |   governance_report    — any governance-domain report
    |
    | Documents themselves live in the Core `files` table.
    | `governance_document_links` is an association table; it does
    | not duplicate file storage.
    |
    */
    'document_categories' => [
        'meeting_notice',
        'agenda',
        'minutes',
        'resolution',
        'appointment_letter',
        'committee_mandate',
        'election_record',
        'declaration',
        'action_evidence',
        'governance_report',
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Fine-grained toggles for capabilities the domain may gain in
    | a later phase. Each flag corresponds to an approved
    | decision; flipping a flag changes behaviour without a schema
    | change.
    |
    | `notifications_enabled` is FALSE and MUST remain false until
    | a Core notification capability exists. See the file header
    | §"Notifications — the one deliberate negative default".
    |
    | `electronic_voting_enabled` is FALSE and MUST remain false
    | unless the organization explicitly approves a separate CR
    | that also documents the security model (AD-GOV-010).
    |
    | `arna_integration_enabled` is FALSE and stays so until G13.
    | It gates whether ARNA may consume governance records through
    | its own APIs. The underlying permission layer
    | (`governance.report.view` etc.) already governs access;
    | this flag is a separate capability gate that reflects the
    | ARNA integration CR's approval.
    |
    */
    'features' => [
        'notifications_enabled'      => (bool) env('MASES_GOVERNANCE_NOTIFICATIONS', false),
        'electronic_voting_enabled'  => false,
        'arna_integration_enabled'   => false,
    ],

];