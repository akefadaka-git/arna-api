<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G1 · File 3 of 10
 * =============================================================================
 *
 * File:            Resources/lang/en/common.php  (NEW)
 * Unit:            G1 — Governance domain foundation (English catalogue)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The Governance domain had no English
 *   translation catalogue. Laravel's translator, once the domain's
 *   provider (G1 file 4) is deployed and registered with a
 *   translation namespace, would resolve any missing key by
 *   returning the key string verbatim rather than a label. Because
 *   the platform's configured `fallback_locale` is `en`, a missing
 *   `en` entry would surface the raw key to any caller in either
 *   locale — the most user-visible failure mode of a missing
 *   catalogue.
 *
 * After:
 *   One file that declares the English labels for every frozen
 *   vocabulary value declared in `Config/governance.php` (G1 file
 *   1) and mirrored in the Amharic catalogue (G1 file 2). This
 *   file is the English counterpart of the Amharic file: same
 *   top-level keys, same nested keys, same order. A reviewer
 *   comparing the two files side-by-side sees a one-to-one
 *   correspondence.
 *
 *   What the catalogue contains:
 *
 *     · The same 13 top-level groups that the Amharic file
 *       declares. Each group holds one string per vocabulary value.
 *       The 71 values across 13 groups are identical in key and
 *       count between the two files.
 *
 *     · The English display labels for those values, using the
 *       natural English term of art for each governance concept.
 *       Where a value matches the name of a well-established
 *       governance concept (e.g. `conflict_of_interest`,
 *       `ex_officio`, `in_progress`), the label is that concept's
 *       conventional English rendering.
 *
 *   What the catalogue deliberately does NOT contain:
 *
 *     · Any key the Amharic file does not also declare. The two
 *       files are keyed in lockstep. A future CR that adds a
 *       vocabulary value adds it to both files in the same
 *       delivery; a CR that renames a key renames it in both.
 *
 *     · Business-entity names. Individual committee names,
 *       governance body names, position names, and term labels
 *       live as attributes on their rows — they are not
 *       vocabulary values and are not translated here.
 *
 *     · Permission labels. Those live in
 *       GovernancePermissionsSeeder (G1 file 7) as PHP constants.
 *
 *     · Role labels. The existing roles already carry `name_en`
 *       values on their rows; Governance does not redefine them.
 *
 *     · Validation messages, empty-state text, page titles, error
 *       copy, or any multi-word user-facing sentence. Those belong
 *       in the phase that introduces the entity and the surface
 *       that renders them.
 *
 * =============================================================================
 * This file is the Laravel fallback locale
 * =============================================================================
 * The platform's configured `fallback_locale` is `en`. When a
 * translation key is absent from the active locale's catalogue,
 * Laravel consults the fallback locale's catalogue before
 * returning the key string. Two consequences follow:
 *
 *   1. If a key is absent from `am/common.php` but present here,
 *      a caller in the `am` locale receives the English string
 *      rather than the key. That is preferable to displaying the
 *      key, but it is not the intended state. The two files are
 *      meant to stay in sync.
 *
 *   2. If a key is absent from BOTH catalogues, a caller in
 *      either locale receives the key string. This is the "no
 *      catalogue" symptom the Amharic file's docblock describes
 *      for a domain with no catalogue at all. Maintaining this
 *      file's key set in lockstep with the Amharic file's is the
 *      mechanism that prevents it.
 *
 * The operational rule: deploy files 2 and 3 in the same batch.
 * Deploying either alone leaves the other locale with no
 * catalogue for the domain's `common` group and produces the
 * fallback behaviour described above.
 *
 * =============================================================================
 * English labels are working terminology, not final terminology
 * =============================================================================
 * The master prompt §32 records two constraints for the Amharic
 * surface: `DEFAULT_LOCALE = am`, and "Do NOT invent final legal
 * terminology where official organizational terminology is
 * available." That second constraint applies with equal force to
 * the English surface.
 *
 * The strings in this file are the English terms of art that the
 * master prompt itself uses. They are the platform's working
 * terminology for the governance domain until the organization
 * formalises an English terminology list alongside its Amharic
 * one — a governance-by-law or dictionary artefact that this
 * project cannot produce on the organization's behalf.
 *
 * Where this file's labels and the organization's eventual
 * English terminology diverge, the organization's terminology
 * wins. Revising a label is a one-line change to one entry in
 * this file; the key remains stable and no consumer of the
 * catalogue is affected.
 *
 * =============================================================================
 * Correspondence with the Amharic file
 * =============================================================================
 * The two catalogues are deliberately mirrored in structure:
 *
 *   Amharic file (file 2)              English file (this file)
 *   ────────────────────────           ────────────────────────
 *   13 top-level groups                13 top-level groups
 *   71 nested values                   71 nested values
 *   Same group names                   Same group names
 *   Same nested keys                   Same nested keys
 *   Same declaration order             Same declaration order
 *
 * A reviewer validating a translation pair reads the two files
 * side by side. Where a term in one file is under native-speaker
 * or legal review, the corresponding term in the other file is
 * subject to the same review.
 *
 * =============================================================================
 * Term-of-art choices worth recording
 * =============================================================================
 * The following English labels represent a specific choice among
 * plausible alternatives. They are recorded here for reviewers
 * who may wish to prefer another rendering.
 *
 * `body_type.board` → "Board"
 *   The full legal name is "Board of Directors". The label is the
 *   short form. A UI that renders the label inside a heading
 *   ("Board" / "Board of Directors") may prefer the full form; a
 *   UI that renders it inside a status column prefers the short
 *   form. The label here is the short form; a future CR may
 *   introduce a sibling `body_type_label_long` if a surface needs
 *   it.
 *
 * `body_type.ad_hoc` → "Ad hoc"
 *   The Latin phrase is retained in English usage. Some style
 *   guides hyphenate ("ad-hoc"); the unhyphenated form is the
 *   more common in governance and business writing. No change is
 *   proposed.
 *
 * `membership_type.ex_officio` → "Ex-officio"
 *   The hyphenated form matches the master prompt §8's own usage.
 *   The hyphenless form ("Ex officio") is also current in English
 *   legal writing. Either is acceptable; the choice here matches
 *   the master prompt.
 *
 * `membership_type.alternate` → "Alternate"
 *   The term distinguishes a designated substitute from an
 *   `acting` member. Some governance documents use "substitute"
 *   or "alternate member"; "Alternate" alone is the shorter label
 *   and matches the master prompt §13's list.
 *
 * `attendance_status.excused` → "Excused"
 *   The label states the outcome (the participant was excused),
 *   not the process (the participant submitted notice). Both
 *   readings are correct for the underlying state; the label
 *   matches the master prompt §15's list.
 *
 * `attendance_status.remote` → "Remote"
 *   The label names the mode of participation, not the fact of
 *   attendance. A remote attendee is also present; the two
 *   statuses in this vocabulary are alternatives, not
 *   complements. This matches the master prompt §15's list.
 *
 * `meeting_status.adjourned` → "Adjourned"
 *   The perfective form of a meeting that has been suspended for
 *   a period and may resume. Distinct from `completed` (the
 *   meeting concluded) and `cancelled` (the meeting will not take
 *   place at all).
 *
 * `resolution_status.adopted` → "Adopted"
 *   Matches the master prompt §19's language ("adopted/final").
 *   The status is distinct from `approved` in the minute-approval
 *   vocabulary; a resolution is `adopted`, a minute is
 *   `approved`. The two vocabularies use different terms
 *   deliberately — the actions are distinct.
 *
 * `resolution_status.superseded` → "Superseded"
 *   Matches the master prompt §19's list. The label describes a
 *   later resolution replacing this one; both remain in the
 *   register.
 *
 * `action_item_status.verified` → "Verified"
 *   Matches the master prompt §21's explicit distinction between
 *   `completed` (the responsible person reports the work done)
 *   and `verified` (an authorized governance member confirms it).
 *   The two are different statuses with different permissions.
 *
 * `delegation_status.revoked` → "Revoked"
 *   The label names the action by which a delegation is withdrawn
 *   before its effective period ends. Distinct from `expired`
 *   (the period ended normally).
 *
 * `confidentiality_level.public` → "Public"
 *   Within the Governance domain, "Public" means "open to the
 *   tenant", not "open to the world". A public governance record
 *   is visible to any authenticated user in the tenant; it is not
 *   published externally. The Amharic counterpart renders this as
 *   "ለሁሉም ክፍት" ("open to everyone"), and both labels are best
 *   understood with the tenant-scoped meaning in mind.
 *
 * `document_category.declaration` → "Declaration"
 *   In this context the term names a document that discloses a
 *   conflict of interest or a related-party relationship — not a
 *   general statement. The Amharic counterpart uses "ማስታወቂያ"
 *   ("announcement"), which is idiomatic but doesn't carry the
 *   disclosure-of-interest sense in its every reading. A future
 *   review may adjust one or both.
 *
 * =============================================================================
 * Native-speaker and legal-terminology review needed
 * =============================================================================
 * The Amharic file (file 2) carries a twelve-item review list
 * covering its own translations. This file's review list is
 * narrower: English terms of art in governance contexts tend to
 * be well-settled, and the master prompt §8–§26 supplies the
 * platform's own preferred renderings.
 *
 * Four items are flagged for review by a governance-domain legal
 * or policy reviewer — not a native-speaker reviewer, but someone
 * familiar with the organization's Articles of Association and
 * by-laws:
 *
 *   · `body_type.board` — short form vs. the full legal name.
 *
 *   · `confidentiality_level.public` — confirm that the internal
 *     meaning ("open to the tenant") matches the organization's
 *     intended meaning for this classification. If the
 *     organization intends "public" to mean external publication,
 *     this label and the Amharic counterpart must change together.
 *
 *   · `document_category.declaration` — confirm that "declaration"
 *     is the term the organization uses for a disclosure of
 *     interest, rather than a broader class of document.
 *
 *   · `membership_type.ex_officio` — confirm the hyphenation
 *     convention the organization prefers.
 *
 * The four items are refinements. None of them blocks delivery of
 * this file. Each is a one-line change to one value in this file
 * (and, for the confidentiality item, a coordinated change to the
 * Amharic file's corresponding value).
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Resources/lang/en/common.php
 *
 * The directory `mases_core/src/Domains/Governance/Resources/lang/en/`
 * must exist. If it does not, create it before uploading — the
 * file's path is the full path.
 *
 * This file has no independent deployment step beyond being
 * uploaded. Until GovernanceServiceProvider (G1 file 4) is
 * deployed and registered in `bootstrap/providers.php` (G1 file
 * 10), it is inert — present on disk but not registered with the
 * translator.
 *
 * Verification after the provider is deployed:
 *
 *     php artisan tinker
 *     >>> __('governance::common.body_type.board')
 *     => "Board"
 *     >>> __('governance::common.action_item_status.verified')
 *     => "Verified"
 *
 * Both lookups must produce the English string, not the key.
 * Note that in the `am` locale these lookups return the Amharic
 * strings instead; the English strings are returned only when the
 * active locale is `en`.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Vocabularies covered:      13 (identical to Amharic file)
 * Labels declared:           71 (identical count to Amharic file)
 * =============================================================================
 */

/*
|--------------------------------------------------------------------------
| MASES Governance Domain — English Catalogue (Common)
|--------------------------------------------------------------------------
|
| The English display labels for every vocabulary value declared in
| Config/governance.php (G1 file 1). Mirrors the Amharic catalogue
| (G1 file 2) in structure: same 13 top-level groups, same 71
| nested values, same declaration order.
|
| This is the platform's Laravel `fallback_locale`. A key absent
| from the Amharic catalogue resolves to this file's value rather
| than the raw key. The two files are meant to remain in lockstep;
| a change to one is a change to both.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Body types
    |--------------------------------------------------------------------------
    |
    | Frozen by the master prompt §8. Values:
    |   assembly, board, committee, subcommittee, ad_hoc
    |
    */
    'body_type' => [
        'assembly'     => 'Assembly',
        'board'        => 'Board',
        'committee'    => 'Committee',
        'subcommittee' => 'Subcommittee',
        'ad_hoc'       => 'Ad hoc',
    ],

    /*
    |--------------------------------------------------------------------------
    | Membership types
    |--------------------------------------------------------------------------
    |
    | Frozen by the master prompt §13. Values:
    |   elected, appointed, ex_officio, representative,
    |   observer, alternate, acting, temporary
    |
    */
    'membership_type' => [
        'elected'        => 'Elected',
        'appointed'      => 'Appointed',
        'ex_officio'     => 'Ex-officio',
        'representative' => 'Representative',
        'observer'       => 'Observer',
        'alternate'      => 'Alternate',
        'acting'         => 'Acting',
        'temporary'      => 'Temporary',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance statuses
    |--------------------------------------------------------------------------
    |
    | Frozen by the master prompt §15. Values:
    |   invited, present, absent, excused, late, remote
    |
    */
    'attendance_status' => [
        'invited' => 'Invited',
        'present' => 'Present',
        'absent'  => 'Absent',
        'excused' => 'Excused',
        'late'    => 'Late',
        'remote'  => 'Remote',
    ],

    /*
    |--------------------------------------------------------------------------
    | Meeting statuses
    |--------------------------------------------------------------------------
    |
    | Forward declaration, consumed by G5. Values:
    |   scheduled, in_progress, adjourned, completed, cancelled
    |
    */
    'meeting_status' => [
        'scheduled'   => 'Scheduled',
        'in_progress' => 'In progress',
        'adjourned'   => 'Adjourned',
        'completed'   => 'Completed',
        'cancelled'   => 'Cancelled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Minute approval statuses
    |--------------------------------------------------------------------------
    |
    | Forward declaration, consumed by G7. Values:
    |   draft, submitted, approved, rejected
    |
    */
    'minute_approval_status' => [
        'draft'     => 'Draft',
        'submitted' => 'Submitted',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolution statuses
    |--------------------------------------------------------------------------
    |
    | Forward declaration, consumed by G8. Values:
    |   draft, submitted, adopted, rejected, superseded
    |
    */
    'resolution_status' => [
        'draft'      => 'Draft',
        'submitted'  => 'Submitted',
        'adopted'    => 'Adopted',
        'rejected'   => 'Rejected',
        'superseded' => 'Superseded',
    ],

    /*
    |--------------------------------------------------------------------------
    | Action-item statuses
    |--------------------------------------------------------------------------
    |
    | Frozen by the master prompt §21. Values:
    |   pending, assigned, in_progress, completed,
    |   verified, overdue, cancelled
    |
    */
    'action_item_status' => [
        'pending'     => 'Pending',
        'assigned'    => 'Assigned',
        'in_progress' => 'In progress',
        'completed'   => 'Completed',
        'verified'    => 'Verified',
        'overdue'     => 'Overdue',
        'cancelled'   => 'Cancelled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delegation statuses
    |--------------------------------------------------------------------------
    |
    | Forward declaration, consumed by G9. Values:
    |   draft, active, expired, revoked
    |
    */
    'delegation_status' => [
        'draft'   => 'Draft',
        'active'  => 'Active',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
    ],

    /*
    |--------------------------------------------------------------------------
    | Declaration types
    |--------------------------------------------------------------------------
    |
    | Frozen by the master prompt §23. Values:
    |   conflict_of_interest, related_party, other
    |
    */
    'declaration_type' => [
        'conflict_of_interest' => 'Conflict of interest',
        'related_party'        => 'Related party',
        'other'                => 'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Declaration review statuses
    |--------------------------------------------------------------------------
    |
    | Forward declaration, consumed by G9. Values:
    |   pending, in_review, reviewed, escalated
    |
    */
    'declaration_review_status' => [
        'pending'   => 'Pending',
        'in_review' => 'In review',
        'reviewed'  => 'Reviewed',
        'escalated' => 'Escalated',
    ],

    /*
    |--------------------------------------------------------------------------
    | Election statuses
    |--------------------------------------------------------------------------
    |
    | Forward declaration, consumed by G10. Values:
    |   planned, nominating, voting, counting, certified,
    |   cancelled
    |
    */
    'election_status' => [
        'planned'    => 'Planned',
        'nominating' => 'Nominating',
        'voting'     => 'Voting',
        'counting'   => 'Counting',
        'certified'  => 'Certified',
        'cancelled'  => 'Cancelled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidentiality levels
    |--------------------------------------------------------------------------
    |
    | Frozen by the master prompt §26. Values:
    |   public, restricted, confidential, secret
    |
    */
    'confidentiality_level' => [
        'public'       => 'Public',
        'restricted'   => 'Restricted',
        'confidential' => 'Confidential',
        'secret'       => 'Secret',
    ],

    /*
    |--------------------------------------------------------------------------
    | Document categories
    |--------------------------------------------------------------------------
    |
    | Frozen by the master prompt §25. Values:
    |   meeting_notice, agenda, minutes, resolution,
    |   appointment_letter, committee_mandate, election_record,
    |   declaration, action_evidence, governance_report
    |
    */
    'document_category' => [
        'meeting_notice'     => 'Meeting notice',
        'agenda'             => 'Agenda',
        'minutes'            => 'Minutes',
        'resolution'         => 'Resolution',
        'appointment_letter' => 'Appointment letter',
        'committee_mandate'  => 'Committee mandate',
        'election_record'    => 'Election record',
        'declaration'        => 'Declaration',
        'action_evidence'    => 'Action evidence',
        'governance_report'  => 'Governance report',
    ],

];