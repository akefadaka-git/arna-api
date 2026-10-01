<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-G2C-CAT-001  ·  Amharic Catalogue  (AMEND)
 * =============================================================================
 *
 * File:            Resources/lang/am/common.php
 * Delivery type:   Full-file replacement (amendment)
 *
 * AMENDMENT NOTE
 * -----------------------------------------------------------------------------
 * G1 file 2 of 10 delivered this file with 13 vocabulary groups and
 * 71 Amharic labels. G2c (files 1–31) introduced two new entities —
 * `GovernanceAppointment` and `GovernanceMembership` — each with its
 * own vocabulary, and no phase ever declared the corresponding
 * translation groups. The four `*_label` fields the two new
 * resources emit fall through to the raw translation key:
 *
 *     __('governance::common.appointment_status.active')
 *     →  "governance::common.appointment_status.active"
 *
 * That is not a malformed response — the resource emits whatever
 * `__()` returns — but it is not what a UI should render.
 *
 * This amendment adds the four missing groups. The keys and their
 * Amharic renderings are drawn from the four G2c enum docblocks
 * (files 2, 3, 4, 17 of 31), each of which recorded the proposed
 * renderings in its own §"What this enum deliberately does NOT do"
 * section:
 *
 *     appointment_instrument_type  (5 keys)  — G2c file 4 of 31
 *     appointment_mode             (4 keys)  — G2c file 3 of 31
 *     appointment_status           (4 keys)  — G2c file 2 of 31
 *     membership_status            (6 keys)  — G2c file 17 of 31
 *
 * The four groups add 19 new labels. The file's total label count
 * moves from 71 to 90.
 *
 * =============================================================================
 * What this amendment deliberately does NOT do
 * =============================================================================
 *   · It does NOT touch any of the 13 pre-existing groups. Every
 *     one of the 71 existing labels is preserved byte-for-byte.
 *
 *   · It does NOT reorder the existing groups. The new groups are
 *     APPENDED at the end of the array, after `document_category`,
 *     so a reviewer comparing the current file to the amended file
 *     sees one contiguous new section rather than scattered
 *     insertions.
 *
 *   · It does NOT amend `Config/governance.php`. The four new
 *     vocabularies are declared authoritatively by the G2c enum
 *     files, not by the config. The config's `body_types`,
 *     `membership_types`, and the eleven forward-declaration
 *     arrays it carries remain unchanged. This is a deliberate
 *     stance: the config declares vocabularies for entities frozen
 *     by the master prompt or for entities that do not yet exist.
 *     The four G2c vocabularies are neither — they emerged in G2c
 *     and their source of truth is the enums.
 *
 *   · It does NOT introduce validation messages. Those belong in
 *     the `*Request` classes (already delivered with inline
 *     Amharic messages — G2c files 10, 11, 23, 24 of 31).
 *
 *   · It does NOT change the fallback behaviour of the resource
 *     layer. The resources emit whatever `__()` returns; the
 *     amendment simply makes the four new groups' keys resolve to
 *     a proper Amharic string instead of the raw key.
 *
 * =============================================================================
 * Reused renderings — deliberately consistent with the existing file
 * =============================================================================
 * Ten of the nineteen new labels are byte-for-byte identical to
 * labels the existing catalogue already declares for OTHER
 * vocabularies. The reuse is deliberate: the underlying concepts
 * are the same, and using two different Amharic renderings for the
 * same English concept would confuse a reader.
 *
 *     appointment_status.draft      = minute_approval_status.draft
 *                                    = 'ረቂቅ'
 *     appointment_status.active     = delegation_status.active
 *                                    = 'ንቁ'
 *     appointment_status.revoked    = delegation_status.revoked
 *                                    = 'የተሰረዘ'
 *     appointment_status.superseded = resolution_status.superseded
 *                                    = 'የተተካ'
 *
 *     appointment_mode.elected      = membership_type.elected
 *                                    = 'የተመረጠ'
 *     appointment_mode.appointed    = membership_type.appointed
 *                                    = 'የተሾመ'
 *     appointment_mode.ex_officio   = membership_type.ex_officio
 *                                    = 'በስልጣን መሠረት'
 *
 *     appointment_instrument_type.resolution
 *                                    = resolution_status.adopted
 *                                     = 'ውሳኔ'
 *
 *     membership_status.active      = delegation_status.active
 *                                    = 'ንቁ'
 *     membership_status.expired     = delegation_status.expired
 *                                    = 'ጊዜው ያለፈበት'
 *
 * The nine genuinely-new renderings are recorded in the
 * §"Native-speaker review needed (G2c addition)" section below.
 *
 * =============================================================================
 * Native-speaker review needed (G2c addition)
 * =============================================================================
 * In addition to the twelve items the original G1 file already
 * flagged, the following nine items are the new candidates for
 * native-speaker review. They are idiomatic as far as this author
 * can determine, but native review is the correct final gate.
 *
 * `appointment_mode.mixed` (`የተቀላቀለ`)
 *   Literally "mixed / blended". The word is standard, but the
 *   register may be adjusted by a reviewer to `የተደባለቀ` or another
 *   rendering. The concept — an acquisition process that includes
 *   both a vote and a prior designation — is new to this domain.
 *
 * `appointment_instrument_type.board_decision`
 * (`የቦርድ ውሳኔ`)
 *   "Board decision". Straight compound of `የቦርድ` ("of the Board",
 *   already used in the catalogue as `body_type.board` = 'ቦርድ')
 *   and `ውሳኔ` ("decision", already used as `resolution_status.*`).
 *
 * `appointment_instrument_type.general_assembly`
 * (`የጠቅላላ ጉባኤ ውሳኔ`)
 *   "General Assembly decision". Compound of `የጠቅላላ ጉባኤ` (already
 *   used as `body_type.assembly` = 'ጠቅላላ ጉባኤ') and `ውሳኔ`.
 *
 * `appointment_instrument_type.external_document`
 * (`የውጭ ሰነድ`)
 *   "External document". Compound of `የውጭ` ("external") and
 *   `ሰነድ` ("document", already used in the `document_category`
 *   labels).
 *
 * `appointment_instrument_type.by_law` (`በሕግ ደንብ`)
 *   "By law / by charter". The phrase is idiomatic in legal
 *   Amharic. A reviewer may prefer `በመተዳደሪያ ደንብ` ("by the charter")
 *   if the by-law in question is specifically the organization's
 *   charter rather than a general statute.
 *
 * `membership_status.ended` (`የተጠናቀቀ`)
 *   "Completed / finished". The word appears in
 *   `meeting_status.completed` = 'ተጠናቅቋል' (a different form of the
 *   same root). A reviewer may prefer the shorter form for
 *   consistency with the existing meeting-status label, at the
 *   cost of losing the "ended naturally" nuance versus "completed
 *   by action".
 *
 * `membership_status.suspended` (`የተገደበ`)
 *   "Restricted / limited". Idiomatic but the noun `ገደብ` also
 *   carries the sense "limit, bound". A reviewer may prefer the
 *   fuller `ለጊዜው የተከለከለ` ("temporarily prohibited") if the
 *   suspension is understood as a disciplinary measure.
 *
 * `membership_status.resigned` (`በፈቃዱ የተለቀቀ`)
 *   "Released by his own will". Standard Amharic rendering for a
 *   voluntary resignation. A reviewer may prefer a shorter form.
 *
 * `membership_status.removed` (`የተሰናበተ`)
 *   "Removed / dismissed". Idiomatic. A reviewer may prefer the
 *   fuller `በስልጣን የተሰናበተ` ("dismissed by authority") to emphasise
 *   the involuntary nature of the removal. If both this and
 *   `resigned` are changed, they must be changed together so the
 *   pair remains distinct.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Resources/lang/am/common.php
 *
 * This file REPLACES the G1 version.
 *
 * After uploading, no composer or artisan command is required —
 * Laravel loads the translation catalogue on demand from the file
 * system. If the translation cache is enabled (production
 * deployments generally enable it), run:
 *
 *     php artisan optimize:clear
 *
 * Verification:
 *
 *     php artisan tinker
 *     >>> __('governance::common.membership_status.active')
 *     => "ንቁ"
 *     >>> __('governance::common.appointment_mode.ex_officio')
 *     => "በስልጣን መሠረት"
 *     >>> __('governance::common.appointment_instrument_type.resolution')
 *     => "ውሳኔ"
 *
 * All three lookups must produce the Amharic string, not the key.
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Vocabulary groups added:   4
 * Labels added:              19
 * Existing groups changed:   0 (preserved byte-for-byte)
 * Total groups after amend:  17
 * Total labels after amend:  90
 * =============================================================================
 */

/*
|--------------------------------------------------------------------------
| MASES Governance Domain — Amharic Catalogue (Common)
|--------------------------------------------------------------------------
|
| The Amharic display labels for every vocabulary value declared in
| Config/governance.php (G1 file 1 of 10) AND every vocabulary
| value declared by a G2c enum (G2c files 2, 3, 4, 17 of 31).
| Consumed by the domain's resource layer via
| `__('governance::common.<group>.<value>')`.
|
| The file is a single flat array with one top-level key per
| vocabulary group.
|
| The first 13 groups below (body_type through document_category)
| mirror the group names declared by Config/governance.php. The
| last 4 groups (appointment_instrument_type, appointment_mode,
| appointment_status, membership_status) are declared
| authoritatively by the four G2c enums and are NOT present in the
| config. See the file-level change-verification block
| §"What this amendment deliberately does NOT do" for the
| reasoning.
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
    | `board` and `committee` are loanwords in modern Amharic
    | business usage and are used as-is.
    |
    */
    'body_type' => [
        'assembly'     => 'ጠቅላላ ጉባኤ',
        'board'        => 'ቦርድ',
        'committee'    => 'ኮሚቴ',
        'subcommittee' => 'ንዑስ ኮሚቴ',
        'ad_hoc'       => 'ጊዜያዊ ኮሚቴ',
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
        'elected'        => 'የተመረጠ',
        'appointed'      => 'የተሾመ',
        'ex_officio'     => 'በስልጣን መሠረት',
        'representative' => 'ተወካይ',
        'observer'       => 'ታዛቢ',
        'alternate'      => 'ተለዋጭ',
        'acting'         => 'ተተኪ',
        'temporary'      => 'ጊዜያዊ',
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
        'invited' => 'የተጋበዘ',
        'present' => 'የተገኘ',
        'absent'  => 'የቀረ',
        'excused' => 'ፈቃድ ያገኘ',
        'late'    => 'ዘግይቶ የደረሰ',
        'remote'  => 'በርቀት የተሳተፈ',
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
        'scheduled'   => 'ተይዟል',
        'in_progress' => 'በሂደት ላይ',
        'adjourned'   => 'ተለያይቷል',
        'completed'   => 'ተጠናቅቋል',
        'cancelled'   => 'ተሰርዟል',
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
        'draft'     => 'ረቂቅ',
        'submitted' => 'የቀረበ',
        'approved'  => 'የጸደቀ',
        'rejected'  => 'ውድቅ የሆነ',
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
        'draft'      => 'ረቂቅ',
        'submitted'  => 'የቀረበ',
        'adopted'    => 'የጸደቀ',
        'rejected'   => 'ውድቅ የሆነ',
        'superseded' => 'የተተካ',
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
    | The distinction between `completed` (responsible person
    | reports done) and `verified` (an authorized party confirms) is
    | structural, not just a vocabulary matter — see the master
    | prompt §21 and Config/governance.php's note on this array.
    |
    */
    'action_item_status' => [
        'pending'     => 'በመጠባበቅ ላይ',
        'assigned'    => 'የተመደበ',
        'in_progress' => 'በሂደት ላይ',
        'completed'   => 'ተጠናቅቋል',
        'verified'    => 'የተረጋገጠ',
        'overdue'     => 'ጊዜው ያለፈበት',
        'cancelled'   => 'ተሰርዟል',
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
        'draft'   => 'ረቂቅ',
        'active'  => 'ንቁ',
        'expired' => 'ጊዜው ያለፈበት',
        'revoked' => 'የተሰረዘ',
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
        'conflict_of_interest' => 'የጥቅም ግጭት',
        'related_party'        => 'ተዛማጅ ወገን',
        'other'                => 'ሌላ',
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
        'pending'   => 'በመጠባበቅ ላይ',
        'in_review' => 'በግምገማ ላይ',
        'reviewed'  => 'የተገመገመ',
        'escalated' => 'ወደ ላይኛው ደረጃ የተላለፈ',
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
        'planned'    => 'ታቅዷል',
        'nominating' => 'እጩዎች በማቅረብ ላይ',
        'voting'     => 'ድምጽ በመስጠት ላይ',
        'counting'   => 'ድምጽ በመቁጠር ላይ',
        'certified'  => 'የተረጋገጠ',
        'cancelled'  => 'ተሰርዟል',
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidentiality levels
    |--------------------------------------------------------------------------
    |
    | Frozen by the master prompt §26. Values:
    |   public, restricted, confidential, secret
    |
    | Ordered from least to most sensitive. The ordering is used by
    | the authorization layer to compare a user's clearance to a
    | record's classification; the labels here are for display only.
    |
    */
    'confidentiality_level' => [
        'public'       => 'ለሁሉም ክፍት',
        'restricted'   => 'የተገደበ',
        'confidential' => 'ሚስጥራዊ',
        'secret'       => 'በጣም ሚስጥራዊ',
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
        'meeting_notice'     => 'የስብሰባ ማስታወቂያ',
        'agenda'             => 'አጀንዳ',
        'minutes'            => 'ቃለ ጉባኤ',
        'resolution'         => 'ውሳኔ',
        'appointment_letter' => 'የሹመት ደብዳቤ',
        'committee_mandate'  => 'የኮሚቴ ሥልጣን',
        'election_record'    => 'የምርጫ መዝገብ',
        'declaration'        => 'ማስታወቂያ',
        'action_evidence'    => 'የእርምጃ ማስረጃ',
        'governance_report'  => 'የአስተዳደር ሪፖርት',
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment instrument types (G2c addition — CR-G2C-CAT-001)
    |--------------------------------------------------------------------------
    |
    | Source of truth: `GovernanceAppointmentInstrumentType` enum
    | (G2c file 4 of 31). Values:
    |   resolution, board_decision, general_assembly,
    |   external_document, by_law
    |
    | This group is NOT declared by Config/governance.php. See the
    | file-level change-verification block
    | §"What this amendment deliberately does NOT do".
    |
    | Two renderings — `board_decision` and `general_assembly` — are
    | compounds that reuse existing vocabulary from the catalogue.
    | The three new compounds are recorded in the §"Native-speaker
    | review needed (G2c addition)" list above.
    |
    */
    'appointment_instrument_type' => [
        'resolution'        => 'ውሳኔ',
        'board_decision'    => 'የቦርድ ውሳኔ',
        'general_assembly'  => 'የጠቅላላ ጉባኤ ውሳኔ',
        'external_document' => 'የውጭ ሰነድ',
        'by_law'            => 'በሕግ ደንብ',
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment modes (G2c addition — CR-G2C-CAT-001)
    |--------------------------------------------------------------------------
    |
    | Source of truth: `GovernanceAppointmentMode` enum (G2c file 3
    | of 31). Values:
    |   elected, appointed, ex_officio, mixed
    |
    | The first three values reuse the corresponding renderings
    | from `membership_type` — the underlying acquisition concepts
    | are the same, and using different Amharic for the same concept
    | across two groups would confuse a reader. The fourth value
    | (`mixed`) is new to the domain.
    |
    */
    'appointment_mode' => [
        'elected'    => 'የተመረጠ',
        'appointed'  => 'የተሾመ',
        'ex_officio' => 'በስልጣን መሠረት',
        'mixed'      => 'የተቀላቀለ',
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment statuses (G2c addition — CR-G2C-CAT-001)
    |--------------------------------------------------------------------------
    |
    | Source of truth: `GovernanceAppointmentStatus` enum (G2c file
    | 2 of 31). Values:
    |   draft, active, revoked, superseded
    |
    | Every value in this group reuses a rendering the catalogue
    | already declares for another vocabulary. See the file-level
    | change-verification block §"Reused renderings" for the mapping.
    |
    */
    'appointment_status' => [
        'draft'      => 'ረቂቅ',
        'active'     => 'ንቁ',
        'revoked'    => 'የተሰረዘ',
        'superseded' => 'የተተካ',
    ],

    /*
    |--------------------------------------------------------------------------
    | Membership statuses (G2c addition — CR-G2C-CAT-001)
    |--------------------------------------------------------------------------
    |
    | Source of truth: `GovernanceMembershipStatus` enum (G2c file
    | 17 of 31). Values:
    |   active, ended, suspended, resigned, removed, expired
    |
    | Two of the six values (`active`, `expired`) reuse renderings
    | the catalogue already declares for `delegation_status`. The
    | remaining four are new to the domain and are recorded in the
    | §"Native-speaker review needed (G2c addition)" list above.
    |
    | The `resigned` and `removed` renderings must remain distinct
    | from one another — a voluntary resignation is a different
    | governance fact from a removal by authority, and a reviewer
    | who adjusts one of the two must adjust the other to preserve
    | the distinction.
    |
    */
    'membership_status' => [
        'active'    => 'ንቁ',
        'ended'     => 'የተጠናቀቀ',
        'suspended' => 'የተገደበ',
        'resigned'  => 'በፈቃዱ የተለቀቀ',
        'removed'   => 'የተሰናበተ',
        'expired'   => 'ጊዜው ያለፈበት',
    ],

];