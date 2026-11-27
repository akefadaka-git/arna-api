<?php

declare(strict_types=1);



/*
| Governance translation catalogue (Amharic).
| Keep keys aligned with the English catalogue and domain enums.
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