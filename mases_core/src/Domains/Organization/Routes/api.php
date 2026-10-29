<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use MaaleArna\\MASES\\Core\\Domains\\Organization\\Http\\Controllers\\OrganizationController;

/*
 * Organization domain owns its HTTP contract. OrganizationServiceProvider
 * applies the configured prefix and the API/authenticated-tenant middleware.
 * Controllers enforce the operation-specific authorization policies.
 */
Route::name('api.organization.')->group(static function (): void {
    Route::get('/units', [OrganizationController::class, 'index'])->name('units.index');
    Route::post('/units', [OrganizationController::class, 'store'])->name('units.store');
    Route::get('/units/{id}', [OrganizationController::class, 'show'])->whereNumber('id')->name('units.show');
    Route::put('/units/{id}', [OrganizationController::class, 'update'])->whereNumber('id')->name('units.update');
    Route::delete('/units/{id}', [OrganizationController::class, 'archive'])->whereNumber('id')->name('units.archive');
    Route::get('/units/{id}/assignments', [OrganizationController::class, 'assignments'])->whereNumber('id')->name('units.assignments.index');
    Route::post('/units/{id}/assignments', [OrganizationController::class, 'assign'])->whereNumber('id')->name('units.assignments.store');
    Route::patch('/assignments/{id}', [OrganizationController::class, 'updateAssignment'])->whereNumber('id')->name('assignments.update');
});
