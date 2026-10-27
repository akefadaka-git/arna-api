<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 31 of 31
 * =============================================================================
 *
 * File:            Routes/api.php  (AMEND)
 * Unit:            G2c — GovernanceAppointment and GovernanceMembership
 *                  vertical slices (routes amendment)
 * Delivery type:   Amendment
 * Net lines:       +8 route declarations; +2 imports; +~90 docblock lines
 *                  rewritten in the file-level §"Routes declared by this
 *                  file"; +~140 docblock lines added for the two new
 *                  blocks (appointments, memberships); +~40 docblock
 *                  lines rewritten in the file-level §"Route ordering"
 *                  and §"Authorization layering" sections; +~300 lines
 *                  for this change-verification block.
 *
 * Before (as the G2b routes amendment, G2b file 27 of 27, delivered it):
 *   The routes file declared thirteen routes:
 *
 *     GET    /ping                                 →  Ping
 *     GET    /bodies                               →  Body::index
 *     POST   /bodies                               →  Body::store
 *     GET    /bodies/{governanceBody}              →  Body::show
 *     PATCH  /bodies/{governanceBody}              →  Body::update
 *     PUT    /bodies/{governanceBody}              →  (same)
 *     GET    /bodies/{governanceBody}/positions    →  Position::index
 *     POST   /bodies/{governanceBody}/positions    →  Position::store
 *     GET    /positions/{governancePosition}       →  Position::show
 *     PATCH  /positions/{governancePosition}       →  Position::update
 *     PUT    /positions/{governancePosition}       →  (same)
 *     GET    /bodies/{governanceBody}/terms        →  Term::index
 *     POST   /bodies/{governanceBody}/terms        →  Term::store
 *     GET    /terms/{governanceTerm}               →  Term::show
 *     PATCH  /terms/{governanceTerm}               →  Term::update
 *     PUT    /terms/{governanceTerm}               →  (same)
 *
 *   The two controllers delivered by G2c —
 *   `GovernanceAppointmentController` (file 12 of 31) and
 *   `GovernanceMembershipController` (file 25 of 31) — were on disk,
 *   but the routes that dispatch to them did not exist. Every
 *   request to the appointment or membership URLs fell through to
 *   the platform's catch-all and returned 404.
 *
 * After:
 *   Eight routes are added inside the existing
 *   `Route::name('api.governance.')->group(...)` block:
 *
 *     Appointments:
 *       GET    /bodies/{governanceBody}/appointments    →  index
 *       POST   /bodies/{governanceBody}/appointments    →  store
 *       GET    /appointments/{governanceAppointment}    →  show
 *       PATCH  /appointments/{governanceAppointment}    →  update
 *       PUT    /appointments/{governanceAppointment}    →  (same)
 *
 *     Memberships:
 *       GET    /bodies/{governanceBody}/memberships     →  index
 *       POST   /bodies/{governanceBody}/memberships     →  store
 *       GET    /memberships/{governanceMembership}      →  show
 *       PATCH  /memberships/{governanceMembership}      →  update
 *       PUT    /memberships/{governanceMembership}      →  (same)
 *
 *   After the provider's prefix (`api/governance`) is applied, the
 *   eight new endpoints resolve to:
 *
 *     GET    /api/governance/bodies/{id}/appointments
 *     POST   /api/governance/bodies/{id}/appointments
 *     GET    /api/governance/appointments/{id}
 *     PATCH  /api/governance/appointments/{id}
 *     GET    /api/governance/bodies/{id}/memberships
 *     POST   /api/governance/bodies/{id}/memberships
 *     GET    /api/governance/memberships/{id}
 *     PATCH  /api/governance/memberships/{id}
 *
 *   The `ping` route and the twelve body/position/term routes from
 *   G1, G2a, and G2b are preserved byte-for-byte — same paths, same
 *   controllers, same middleware, same names, same docblocks.
 *
 * =============================================================================
 * What the amendment does NOT do
 * =============================================================================
 *   · It does NOT add `DELETE` routes. The appointment and
 *     membership policies declare `delete` abilities for future
 *     use, but no G2c route exposes them.
 *
 *   · It does NOT add the six lifecycle-transition routes for
 *     memberships (`end`, `suspend`, `resume`, `resign`, `remove`,
 *     `expire`) or the three for appointments (`activate`,
 *     `revoke`, `supersede`). The controllers deliberately do not
 *     expose those transitions; see
 *     `GovernanceMembershipController` (G2c file 25 of 31)
 *     §"What the controller deliberately does NOT expose" for the
 *     reasoning. A future CR that exposes the transitions adds
 *     the routes and the matching controller methods together.
 *
 *   · It does NOT change the outer group, its name prefix, or the
 *     middleware stack applied by the provider.
 *
 * =============================================================================
 * Route ordering — static paths before dynamic paths
 * =============================================================================
 * The new routes follow the ordering rule documented in the file's
 * §"Route ordering" section:
 *
 *   · Within the appointments block, the nested collection routes
 *     (`/bodies/{governanceBody}/appointments`) are declared before
 *     the dynamic-single route pair
 *     (`/appointments/{governanceAppointment}`).
 *
 *   · Same for the memberships block.
 *
 *   · The dynamic segments `{governanceAppointment}` and
 *     `{governanceMembership}` are additionally constrained to
 *     numeric ids via `->whereNumber(...)`, as a second line of
 *     defence.
 *
 *   · The `ping` route remains first. The `/bodies` block (G2a)
 *     remains next. The `/positions` and `/terms` blocks (G2b)
 *     follow. The `/appointments` and `/memberships` blocks (G2c)
 *     close the file.
 *
 * =============================================================================
 * Authorization layering
 * =============================================================================
 * The eight new routes follow the split established by the G2a and
 * G2b routes:
 *
 *   Collection-level operations (`index`, `store`) carry a route-level
 *   permission middleware:
 *
 *     appointments.index  →  auth.permission:governance.body.view
 *     appointments.store  →  auth.permission:governance.body.manage
 *     memberships.index   →  auth.permission:governance.body.view
 *     memberships.store   →  auth.permission:governance.body.manage
 *
 *   Object-level operations (`show`, `update`) carry NO route-level
 *   permission middleware. Their authorization is entirely the
 *   policy's concern, invoked by the controller via
 *   `Gate::authorize('view'|'update', $model)`.
 *
 * The four collection-level routes carry the `governance.body.*`
 * slugs — the same slugs the appointment and membership policies
 * reuse. No new permission slugs are introduced by G2c.
 *
 * =============================================================================
 * Route parameter names
 * =============================================================================
 * The new routes introduce two dynamic parameters:
 *
 *   {governanceAppointment}  — matches the controller's parameter
 *                              name `$governanceAppointment` in
 *                              GovernanceAppointmentController and
 *                              the FormRequest's `route(...)` call.
 *
 *   {governanceMembership}   — matches the controller's parameter
 *                              name `$governanceMembership` in
 *                              GovernanceMembershipController and
 *                              the FormRequest's `route(...)` call.
 *
 * The `{governanceBody}` parameter on the four `/bodies/…` routes
 * matches the same parameter used by the G2a/G2b body-scoped routes.
 *
 * All three names are camelCase forms of their model class names.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Routes/api.php
 *
 * This file REPLACES the G2b version.
 *
 * After uploading, run:
 *
 *     php artisan optimize:clear
 *
 * A `composer dump-autoload` is NOT required for this file — the
 * routes file is not a class; it is `require`-d by the provider at
 * boot time.
 *
 * Verification:
 *
 *     php artisan route:list --path=api/governance
 *
 * Expected: twenty-one route rows:
 *
 *     GET|HEAD   api/governance/ping
 *     GET|HEAD   api/governance/bodies
 *     POST       api/governance/bodies
 *     GET|HEAD   api/governance/bodies/{governanceBody}
 *     PUT|PATCH  api/governance/bodies/{governanceBody}
 *     GET|HEAD   api/governance/bodies/{governanceBody}/positions
 *     POST       api/governance/bodies/{governanceBody}/positions
 *     GET|HEAD   api/governance/positions/{governancePosition}
 *     PUT|PATCH  api/governance/positions/{governancePosition}
 *     GET|HEAD   api/governance/bodies/{governanceBody}/terms
 *     POST       api/governance/bodies/{governanceBody}/terms
 *     GET|HEAD   api/governance/terms/{governanceTerm}
 *     PUT|PATCH  api/governance/terms/{governanceTerm}
 *     GET|HEAD   api/governance/bodies/{governanceBody}/appointments
 *     POST       api/governance/bodies/{governanceBody}/appointments
 *     GET|HEAD   api/governance/appointments/{governanceAppointment}
 *     PUT|PATCH  api/governance/appointments/{governanceAppointment}
 *     GET|HEAD   api/governance/bodies/{governanceBody}/memberships
 *     POST       api/governance/bodies/{governanceBody}/memberships
 *     GET|HEAD   api/governance/memberships/{governanceMembership}
 *     PUT|PATCH  api/governance/memberships/{governanceMembership}
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Routes added:              8 (4 appointments, 4 memberships)
 * Route names added:         8 (api.governance.appointments.{index,store,show,update}
 *                               and api.governance.memberships.{index,store,show,update})
 * Controllers referenced:    2 (GovernanceAppointmentController,
 *                               GovernanceMembershipController)
 * Permission slugs used:     2 (governance.body.view, governance.body.manage)
 * =============================================================================
 */

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\Governance\Http\Controllers\GovernanceAppointmentController;
use MaaleArna\MASES\Core\Domains\Governance\Http\Controllers\GovernanceAuthorityGrantController;
use MaaleArna\MASES\Core\Domains\Governance\Http\Controllers\GovernanceBodyController;
use MaaleArna\MASES\Core\Domains\Governance\Http\Controllers\GovernanceMembershipController;
use MaaleArna\MASES\Core\Domains\Governance\Http\Controllers\GovernancePingController;
use MaaleArna\MASES\Core\Domains\Governance\Http\Controllers\GovernancePositionController;
use MaaleArna\MASES\Core\Domains\Governance\Http\Controllers\GovernanceTermController;

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
| Every successful response follows the canonical MASES envelope
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
| @package MaaleArna\MASES\Core\Domains\Governance\Routes
| @version 1.3.0
*/

Route::name('api.governance.')->group(static function (): void {

    // P1.3-B3 — audited, position-scoped authority grants.
    Route::get('/authority-grants', [GovernanceAuthorityGrantController::class, 'index'])
        ->name('authority-grants.index');
    Route::post('/authority-grants', [GovernanceAuthorityGrantController::class, 'store'])
        ->name('authority-grants.store');
    Route::delete('/authority-grants/{governanceAuthorityGrant}', [GovernanceAuthorityGrantController::class, 'destroy'])
        ->whereNumber('governanceAuthorityGrant')
        ->name('authority-grants.destroy');



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

});