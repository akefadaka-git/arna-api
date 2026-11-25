<?php

declare(strict_types=1);



/*
| Governance translation catalogue (English fallback).
| Keep keys aligned with the Amharic catalogue and domain enums.
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