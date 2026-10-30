<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\Organization\Http\Controllers\OrganizationController;

/*
 * Organization domain HTTP surface.
 *
 * OrganizationServiceProvider mounts these relative routes under the
 * configured API prefix, route-name prefix, and tenant-authentication stack.
 * Keep route declarations here rather than in the platform route catalogue.
 */
Route::get('/audit-events', [OrganizationController::class, 'auditEvents'])->name('audit-events.index');
Route::get('/units', [OrganizationController::class, 'index'])->name('units.index');
Route::post('/units', [OrganizationController::class, 'store'])->name('units.store');
Route::get('/units/{id}', [OrganizationController::class, 'show'])->whereNumber('id')->name('units.show');
Route::put('/units/{id}', [OrganizationController::class, 'update'])->whereNumber('id')->name('units.update');
Route::delete('/units/{id}', [OrganizationController::class, 'archive'])->whereNumber('id')->name('units.archive');
Route::get('/units/{id}/assignments', [OrganizationController::class, 'assignments'])->whereNumber('id')->name('units.assignments.index');
Route::post('/units/{id}/assignments', [OrganizationController::class, 'assign'])->whereNumber('id')->name('units.assignments.store');
Route::patch('/assignments/{id}', [OrganizationController::class, 'updateAssignment'])->whereNumber('id')->name('assignments.update');

Route::post('/numbering/branches', [OrganizationController::class, 'registerBranchNumber'])->name('numbering.branches.store');
Route::post('/numbering/departments', [OrganizationController::class, 'registerDepartmentNumber'])->name('numbering.departments.store');

Route::post('/numbering/branches/{id}/retire', [OrganizationController::class, 'retireBranchNumber'])->whereNumber('id')->name('numbering.branches.retire');
Route::post('/numbering/departments/{id}/retire', [OrganizationController::class, 'retireDepartmentNumber'])->whereNumber('id')->name('numbering.departments.retire');
