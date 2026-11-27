<?php

declare(strict_types=1);



use Illuminate\Support\Facades\Route;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceAppointmentController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceAuthorityGrantController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceBodyController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCommitteeSecurityController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionAuthorityController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCommitteeAppointmentController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceMeetingAttendanceController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceMembershipController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernancePingController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernancePositionController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceSeatController;
use MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceTermController;

/*
|--------------------------------------------------------------------------
| Governance Domain — API Routes
|--------------------------------------------------------------------------
|
| This file defines the HTTP surface of the Governance domain. It is
| loaded by GovernanceServiceProvider::loadDomainRoutes() (G1 file 4
| of 10, amended by G2a file 15 of 18, G2b file 26 of 27, and G2c
| file 30 of 31) inside a group already prefixed by
| config('governance.routes.prefix') and wrapped in the middleware
| stack declared by config('governance.routes.middleware').
|
| Because the outer prefix and middleware are applied by the
| provider, the routes declared below are RELATIVE.
|
| -----------------------------------------------------------------------------
| Routes declared by this file
| -----------------------------------------------------------------------------
| As of G2c:
|
|     GET    /ping                                  →  Ping::show
|
|     GET    /bodies                                →  Body::index
|     POST   /bodies                                →  Body::store
|     GET    /bodies/{governanceBody}               →  Body::show
|     PATCH  /bodies/{governanceBody}               →  Body::update
|     PUT    /bodies/{governanceBody}               →  (same)
|
|     GET    /bodies/{governanceBody}/positions     →  Position::index
|     POST   /bodies/{governanceBody}/positions     →  Position::store
|     GET    /positions/{governancePosition}        →  Position::show
|     PATCH  /positions/{governancePosition}        →  Position::update
|     PUT    /positions/{governancePosition}        →  (same)
|
|     GET    /bodies/{governanceBody}/terms         →  Term::index
|     POST   /bodies/{governanceBody}/terms         →  Term::store
|     GET    /terms/{governanceTerm}                →  Term::show
|     PATCH  /terms/{governanceTerm}                →  Term::update
|     PUT    /terms/{governanceTerm}                →  (same)
|
|     GET    /bodies/{governanceBody}/appointments  →  Appointment::index
|     POST   /bodies/{governanceBody}/appointments  →  Appointment::store
|     GET    /appointments/{governanceAppointment}  →  Appointment::show
|     PATCH  /appointments/{governanceAppointment}  →  Appointment::update
|     PUT    /appointments/{governanceAppointment}  →  (same)
|
|     GET    /bodies/{governanceBody}/memberships   →  Membership::index
|     POST   /bodies/{governanceBody}/memberships   →  Membership::store
|     GET    /memberships/{governanceMembership}    →  Membership::show
|     PATCH  /memberships/{governanceMembership}    →  Membership::update
|     PUT    /memberships/{governanceMembership}    →  (same)
|
| As the domain grows in G3 and beyond, each phase appends its routes
| to this file inside the same `Route::name('api.governance.')->group(...)`
| block.
|
| -----------------------------------------------------------------------------
| Route parameter names
| -----------------------------------------------------------------------------
| Route parameter names MUST match the parameter names of the
| corresponding controller methods. The five dynamic parameters in
| this file are:
|
|     {governanceBody}         →  GovernanceBody $governanceBody
|     {governancePosition}     →  GovernancePosition $governancePosition
|     {governanceTerm}         →  GovernanceTerm $governanceTerm
|     {governanceAppointment}  →  GovernanceAppointment $governanceAppointment
|     {governanceMembership}   →  GovernanceMembership $governanceMembership
|
| A rename of any parameter in this file must be mirrored in the
| corresponding controller method signature, and — for the four
| {governancePosition}, {governanceTerm}, {governanceAppointment},
| and {governanceMembership} parameters — in the corresponding
| FormRequest's `route(...)` call.
|
| -----------------------------------------------------------------------------
| Route ordering
| -----------------------------------------------------------------------------
| The file's structural ordering, established at G2a and preserved
| through G2c:
|
|   1. The `/ping` route.
|   2. The `/bodies` block, with static `/bodies` routes declared
|      before the dynamic `/bodies/{governanceBody}` routes.
|   3. The `/bodies/{governanceBody}/positions` and
|      `/positions/{governancePosition}` blocks.
|   4. The `/bodies/{governanceBody}/terms` and
|      `/terms/{governanceTerm}` blocks.
|   5. The `/bodies/{governanceBody}/appointments` and
|      `/appointments/{governanceAppointment}` blocks.
|   6. The `/bodies/{governanceBody}/memberships` and
|      `/memberships/{governanceMembership}` blocks.
|
| Within each block, the collection-level routes (`index`, `store`)
| appear before the object-level routes (`show`, `update`). The
| ordering is not strictly required by Laravel — the dynamic
| parameters are constrained to numeric ids — but it preserves the
| file's self-documenting structure.
|
| -----------------------------------------------------------------------------
| Authorization layering
| -----------------------------------------------------------------------------
| The three-tier pattern established at G2a continues:
|
|   1. Route-level permission middleware on collection-level routes:
|
|        bodies.index, positions.index, terms.index,
|        appointments.index, memberships.index   →  governance.body.view
|
|        bodies.store, positions.store, terms.store,
|        appointments.store, memberships.store   →  governance.body.manage
|
|   2. Policy-layer authorization invoked by controllers for
|      object-level routes (`show`, `update`).
|
|   3. The `/ping` route carries a single permission
|      (`auth.permission:governance.view`).
|
| -----------------------------------------------------------------------------
| Response envelope
| -----------------------------------------------------------------------------
| Every successful response follows the canonical ARNA envelope
| `{ "success": true, "data": ... }`. The five `index` actions add a
| `meta` block with pagination data.
|
| -----------------------------------------------------------------------------
| Route name namespace
| -----------------------------------------------------------------------------
| All route names are prefixed with `api.governance.`. The full names
| as of G2c:
|
|     api.governance.ping
|     api.governance.bodies.{index,store,show,update}
|     api.governance.positions.{index,store,show,update}
|     api.governance.terms.{index,store,show,update}
|     api.governance.appointments.{index,store,show,update}
|     api.governance.memberships.{index,store,show,update}
|
| Future phases add to this list: `api.governance.meetings.*` (G5),
| and so on.
|
| @package MaaleArna\Arna\Core\Domains\Governance\Routes
| @version 1.3.0
*/

Route::name('api.governance.')->group(static function (): void {

    // P1.3-B3 — audited, position-scoped authority grants.
    Route::get('/authority-grants', [GovernanceAuthorityGrantController::class, 'index'])
        ->middleware('auth.permission:governance.body.manage')
        ->name('authority-grants.index');
    Route::post('/authority-grants', [GovernanceAuthorityGrantController::class, 'store'])
        ->middleware('auth.permission:governance.body.manage')
        ->name('authority-grants.store');
    Route::delete('/authority-grants/{governanceAuthorityGrant}', [GovernanceAuthorityGrantController::class, 'destroy'])
        ->middleware('auth.permission:governance.body.manage')
        ->whereNumber('governanceAuthorityGrant')
        ->name('authority-grants.destroy');

    // P1.3-B1 — explicit seat occupancy and bounded acting assignments.
    Route::get('/seats', [GovernanceSeatController::class, 'index'])
        ->middleware('auth.permission:governance.body.manage')
        ->name('seats.index');
    Route::post('/seats', [GovernanceSeatController::class, 'store'])
        ->middleware('auth.permission:governance.body.manage')
        ->name('seats.store');
    Route::post('/seats/{governanceSeat}/occupancies', [GovernanceSeatController::class, 'occupy'])
        ->middleware('auth.permission:governance.body.manage')
        ->whereNumber('governanceSeat')
        ->name('seats.occupancies.store');
    Route::patch('/occupancies/{governanceSeatOccupancy}/end', [GovernanceSeatController::class, 'endOccupancy'])
        ->middleware('auth.permission:governance.body.manage')
        ->whereNumber('governanceSeatOccupancy')
        ->name('occupancies.end');
    Route::post('/seats/{governanceSeat}/acting-assignments', [GovernanceSeatController::class, 'assignActing'])
        ->middleware('auth.permission:governance.body.manage')
        ->whereNumber('governanceSeat')
        ->name('seats.acting-assignments.store');
    Route::delete('/acting-assignments/{governanceActingAssignment}', [GovernanceSeatController::class, 'revokeActing'])
        ->middleware('auth.permission:governance.body.manage')
        ->whereNumber('governanceActingAssignment')
        ->name('acting-assignments.destroy');



    // =========================================================================
    // Ping — G1 smoke endpoint
    // =========================================================================
    //
    // Routes:
    //     GET  /api/governance/ping
    // =========================================================================

    Route::get('/ping', [GovernancePingController::class, 'show'])
        ->middleware('auth.permission:governance.view')
        ->name('ping');

    // =========================================================================
    // Governance bodies — the domain's primary resource (G2a)
    // =========================================================================

    Route::get('/bodies', [GovernanceBodyController::class, 'index'])
        ->name('bodies.index');

    Route::post('/bodies', [GovernanceBodyController::class, 'store'])
        ->name('bodies.store');

    Route::get('/bodies/{governanceBody}', [GovernanceBodyController::class, 'show'])
        ->whereNumber('governanceBody')
        ->name('bodies.show');

    Route::match(['patch', 'put'], '/bodies/{governanceBody}', [GovernanceBodyController::class, 'update'])
        ->whereNumber('governanceBody')
        ->name('bodies.update');

    // =========================================================================
    // Governance positions — nested under a body (G2b)
    // =========================================================================

    Route::get('/bodies/{governanceBody}/positions', [GovernancePositionController::class, 'index'])
        ->whereNumber('governanceBody')
        ->name('positions.index');

    Route::post('/bodies/{governanceBody}/positions', [GovernancePositionController::class, 'store'])
        ->whereNumber('governanceBody')
        ->name('positions.store');

    Route::get('/positions/{governancePosition}', [GovernancePositionController::class, 'show'])
        ->whereNumber('governancePosition')
        ->name('positions.show');

    Route::match(['patch', 'put'], '/positions/{governancePosition}', [GovernancePositionController::class, 'update'])
        ->whereNumber('governancePosition')
        ->name('positions.update');

    // =========================================================================
    // Governance terms — nested under a body (G2b)
    // =========================================================================

    Route::get('/bodies/{governanceBody}/terms', [GovernanceTermController::class, 'index'])
        ->whereNumber('governanceBody')
        ->name('terms.index');

    Route::post('/bodies/{governanceBody}/terms', [GovernanceTermController::class, 'store'])
        ->whereNumber('governanceBody')
        ->name('terms.store');

    Route::get('/terms/{governanceTerm}', [GovernanceTermController::class, 'show'])
        ->whereNumber('governanceTerm')
        ->name('terms.show');

    Route::match(['patch', 'put'], '/terms/{governanceTerm}', [GovernanceTermController::class, 'update'])
        ->whereNumber('governanceTerm')
        ->name('terms.update');

    // =========================================================================
    // Governance appointments — nested under a body (G2c)
    // =========================================================================
    //
    // ORDER: nested `/bodies/{governanceBody}/appointments` routes
    // before the dynamic `/appointments/{governanceAppointment}`
    // routes.
    //
    // `index` and `store` carry route-level permission middleware.
    // `show` and `update` delegate authorization to the
    // GovernanceAppointmentPolicy via the controller's
    // Gate::authorize() calls.
    //
    // The `{governanceAppointment}` segment is constrained to
    // numeric ids via `->whereNumber(...)`.
    //
    // Routes:
    //     GET    /api/governance/bodies/{governanceBody}/appointments
    //     POST   /api/governance/bodies/{governanceBody}/appointments
    //     GET    /api/governance/appointments/{governanceAppointment}
    //     PATCH  /api/governance/appointments/{governanceAppointment}
    //     PUT    /api/governance/appointments/{governanceAppointment}
    // =========================================================================

    Route::get('/bodies/{governanceBody}/appointments', [GovernanceAppointmentController::class, 'index'])
        ->whereNumber('governanceBody')
        ->name('appointments.index');

    Route::post('/bodies/{governanceBody}/appointments', [GovernanceAppointmentController::class, 'store'])
        ->whereNumber('governanceBody')
        ->name('appointments.store');

    Route::get('/appointments/{governanceAppointment}', [GovernanceAppointmentController::class, 'show'])
        ->whereNumber('governanceAppointment')
        ->name('appointments.show');

    Route::match(['patch', 'put'], '/appointments/{governanceAppointment}', [GovernanceAppointmentController::class, 'update'])
        ->whereNumber('governanceAppointment')
        ->name('appointments.update');

    // =========================================================================
    // Governance memberships — nested under a body (G2c)
    // =========================================================================
    //
    // ORDER: nested `/bodies/{governanceBody}/memberships` routes
    // before the dynamic `/memberships/{governanceMembership}` routes.
    //
    // `index` and `store` carry route-level permission middleware.
    // `show` and `update` delegate authorization to the
    // GovernanceMembershipPolicy via the controller's
    // Gate::authorize() calls.
    //
    // The `{governanceMembership}` segment is constrained to numeric
    // ids via `->whereNumber(...)`.
    //
    // Routes:
    //     GET    /api/governance/bodies/{governanceBody}/memberships
    //     POST   /api/governance/bodies/{governanceBody}/memberships
    //     GET    /api/governance/memberships/{governanceMembership}
    //     PATCH  /api/governance/memberships/{governanceMembership}
    //     PUT    /api/governance/memberships/{governanceMembership}
    // =========================================================================

    Route::get('/bodies/{governanceBody}/memberships', [GovernanceMembershipController::class, 'index'])
        ->whereNumber('governanceBody')
        ->name('memberships.index');

    Route::post('/bodies/{governanceBody}/memberships', [GovernanceMembershipController::class, 'store'])
        ->whereNumber('governanceBody')
        ->name('memberships.store');

    Route::get('/memberships/{governanceMembership}', [GovernanceMembershipController::class, 'show'])
        ->whereNumber('governanceMembership')
        ->name('memberships.show');

    Route::match(['patch', 'put'], '/memberships/{governanceMembership}', [GovernanceMembershipController::class, 'update'])
        ->whereNumber('governanceMembership')
        ->name('memberships.update');


    // G5 A3 — meeting attendance, proxies, penalties and Finance settlement requests.
    Route::get('/meetings', [GovernanceMeetingAttendanceController::class, 'index'])
        ->middleware('auth.permission:governance.body.view')
        ->name('meetings.index');
    Route::post('/meetings', [GovernanceMeetingAttendanceController::class, 'store'])
        ->middleware('auth.permission:governance.body.manage')
        ->name('meetings.store');
    Route::get('/meetings/{meeting}', [GovernanceMeetingAttendanceController::class, 'show'])
        ->whereNumber('meeting')
        ->middleware('auth.permission:governance.body.view')
        ->name('meetings.show');
    Route::post('/meetings/{meeting}/attendance', [GovernanceMeetingAttendanceController::class, 'recordAttendance'])
        ->whereNumber('meeting')
        ->middleware('auth.permission:governance.body.manage')
        ->name('meetings.attendance.store');
    Route::post('/meetings/{meeting}/proxies', [GovernanceMeetingAttendanceController::class, 'submitProxy'])
        ->whereNumber('meeting')
        ->middleware('auth.permission:governance.body.manage')
        ->name('meetings.proxies.store');
    Route::patch('/proxies/{proxy}/review', [GovernanceMeetingAttendanceController::class, 'reviewProxy'])
        ->whereNumber('proxy')
        ->middleware('auth.permission:governance.body.manage')
        ->name('proxies.review');
    Route::post('/meetings/{meeting}/penalties', [GovernanceMeetingAttendanceController::class, 'assessPenalty'])
        ->whereNumber('meeting')
        ->middleware('auth.permission:governance.body.manage')
        ->name('meetings.penalties.store');
    Route::post('/penalties/{penalty}/settlements', [GovernanceMeetingAttendanceController::class, 'recordSettlement'])
        ->whereNumber('penalty')
        ->middleware('auth.permission:governance.body.manage')
        ->name('penalties.settlements.store');

    Route::post('/meetings/{meeting}/attendance/corrections', [GovernanceMeetingAttendanceController::class, 'requestAttendanceCorrection'])
        ->whereNumber('meeting')
        ->middleware('auth.permission:governance.body.manage')
        ->name('meetings.attendance.corrections.store');
    Route::patch('/attendance-corrections/{correction}/review', [GovernanceMeetingAttendanceController::class, 'reviewAttendanceCorrection'])
        ->whereNumber('correction')
        ->middleware('auth.permission:governance.body.manage')
        ->name('attendance-corrections.review');
    Route::post('/penalties/{penalty}/disputes', [GovernanceMeetingAttendanceController::class, 'submitPenaltyDispute'])
        ->whereNumber('penalty')
        ->middleware('auth.permission:governance.body.manage')
        ->name('penalties.disputes.store');
    Route::patch('/penalty-disputes/{dispute}/review', [GovernanceMeetingAttendanceController::class, 'reviewPenaltyDispute'])
        ->whereNumber('dispute')
        ->middleware('auth.permission:governance.body.manage')
        ->name('penalty-disputes.review');

    // Election committee security — election-scoped authority, conflict and audit controls.
    Route::post('/elections/{election}/committee/admins', [GovernanceElectionCommitteeSecurityController::class, 'grantAdministrator'])
        ->whereNumber('election')
        ->middleware('auth.permission:governance.election.committee.administer')
        ->name('elections.committee.admins.store');

    Route::patch('/elections/{election}/committee/admins/{userId}/revoke', [GovernanceElectionCommitteeSecurityController::class, 'revokeAdministrator'])
        ->whereNumber('election')
        ->whereNumber('userId')
        ->middleware('auth.permission:governance.election.committee.administer')
        ->name('elections.committee.admins.revoke');

    Route::post('/elections/{election}/committee/conflicts', [GovernanceElectionCommitteeSecurityController::class, 'declareConflict'])
        ->whereNumber('election')
        ->middleware('auth.permission:governance.election.committee.conflict.declare')
        ->name('elections.committee.conflicts.store');

    Route::get('/elections/{election}/committee/security-events', [GovernanceElectionCommitteeSecurityController::class, 'securityEvents'])
        ->whereNumber('election')
        ->middleware('auth.permission:governance.election.committee.audit.view')
        ->name('elections.committee.security-events.index');


    // Authority evidence workflow — record, review and verify before lifecycle authorization.
    Route::get('/elections/{election}/authority-evidence', [GovernanceElectionAuthorityController::class, 'index'])
        ->whereNumber('election')
        ->middleware('auth.permission:governance.election.view')
        ->name('elections.authority-evidence.index');

    Route::post('/elections/{election}/authority-evidence', [GovernanceElectionAuthorityController::class, 'store'])
        ->whereNumber('election')
        ->middleware('auth.permission:governance.election.manage')
        ->name('elections.authority-evidence.store');

    Route::patch('/elections/{election}/authority-evidence/{evidence}/review', [GovernanceElectionAuthorityController::class, 'review'])
        ->whereNumber('election')
        ->whereNumber('evidence')
        ->middleware('auth.permission:governance.election.authority.review')
        ->name('elections.authority-evidence.review');

    Route::post('/elections/{election}/authority-evidence/{evidence}/verify', [GovernanceElectionAuthorityController::class, 'verify'])
        ->whereNumber('election')
        ->whereNumber('evidence')
        ->middleware('auth.permission:governance.election.authority.verify')
        ->name('elections.authority-evidence.verify');

    // Committee appointments — require verified election authority and reviewed evidence.
    Route::get('/elections/{election}/committee/members', [GovernanceElectionCommitteeAppointmentController::class, 'index'])
        ->whereNumber('election')
        ->middleware('auth.permission:governance.election.committee.view')
        ->name('elections.committee.members.index');

    Route::post('/elections/{election}/committee/members', [GovernanceElectionCommitteeAppointmentController::class, 'store'])
        ->whereNumber('election')
        ->middleware('auth.permission:governance.election.committee.appoint')
        ->name('elections.committee.members.store');

    Route::patch('/elections/{election}/committee/members/{member}/revoke', [GovernanceElectionCommitteeAppointmentController::class, 'revoke'])
        ->whereNumber('election')
        ->whereNumber('member')
        ->middleware('auth.permission:governance.election.committee.revoke')
        ->name('elections.committee.members.revoke');

    // Module 1 — tenant-scoped election foundation and lifecycle.
    Route::get('/elections', [GovernanceElectionController::class, 'index'])
        ->middleware('auth.permission:governance.election.view')
        ->name('elections.index');
    Route::post('/elections', [GovernanceElectionController::class, 'store'])
        ->middleware('auth.permission:governance.election.manage')
        ->name('elections.store');
    Route::get('/elections/{election}', [GovernanceElectionController::class, 'show'])
        ->whereNumber('election')
        ->middleware('auth.permission:governance.election.view')
        ->name('elections.show');
    Route::post('/elections/{election}/transitions', [GovernanceElectionController::class, 'transition'])
        ->whereNumber('election')
        ->middleware('auth.permission:governance.election.manage')
        ->name('elections.transitions.store');


    // Module 2 — candidate intake, nomination, consent and human eligibility decisions.
    Route::post('/elections/{election}/nomination-window', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'configureWindow'])
        ->whereNumber('election')->middleware('auth.permission:governance.election.manage')->name('elections.nomination-window.configure');
    Route::post('/elections/{election}/nomination-window/open', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'openWindow'])
        ->whereNumber('election')->middleware('auth.permission:governance.election.manage')->name('elections.nomination-window.open');
    Route::post('/elections/{election}/nomination-window/close', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'closeWindow'])
        ->whereNumber('election')->middleware('auth.permission:governance.election.manage')->name('elections.nomination-window.close');
    Route::post('/elections/{election}/nominations', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'submitNomination'])
        ->whereNumber('election')->name('elections.nominations.store');
    Route::match(['patch', 'put'], '/elections/{election}/nominations/{submission}', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'correctNomination'])
        ->whereNumber('election')->whereNumber('submission')->name('elections.nominations.correct');
    Route::post('/elections/{election}/nominations/{submission}/withdraw', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'withdrawNomination'])
        ->whereNumber('election')->whereNumber('submission')->name('elections.nominations.withdraw');
    Route::get('/elections/{election}/candidates', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'index'])
        ->whereNumber('election')->middleware('auth.permission:governance.election.view')->name('elections.candidates.index');
    Route::post('/elections/{election}/candidates/{candidate}/consent', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'consent'])
        ->whereNumber('election')->whereNumber('candidate')->name('elections.candidates.consent');
    Route::post('/elections/{election}/candidates/{candidate}/declarations', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'declarations'])
        ->whereNumber('election')->whereNumber('candidate')->name('elections.candidates.declarations');
    Route::post('/elections/{election}/candidates/{candidate}/evidence', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'evidence'])
        ->whereNumber('election')->middleware('auth.permission:governance.election.manage')->name('elections.candidates.evidence');
    Route::post('/elections/{election}/candidates/{candidate}/eligibility-decision', [\MaaleArna\Arna\Core\Domains\Governance\Http\Controllers\GovernanceElectionCandidateController::class, 'decide'])
        ->whereNumber('election')->middleware('auth.permission:governance.election.committee.decide')->name('elections.candidates.eligibility-decision');

});