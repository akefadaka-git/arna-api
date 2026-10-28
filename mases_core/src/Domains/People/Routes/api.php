<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\People\Http\Controllers\PersonContactPointController;
use MaaleArna\MASES\Core\Domains\People\Http\Controllers\PersonController;
use MaaleArna\MASES\Core\Domains\People\Http\Controllers\PersonParticipationController;
use MaaleArna\MASES\Core\Domains\People\Http\Controllers\PersonRelationshipController;

/*
 * People domain routes. PeopleServiceProvider applies the configured
 * /api/people prefix and the api/auth/tenant-membership middleware stack.
 * Per-action permissions remain explicit on each route.
 */
Route::name('api.people.')->group(static function (): void {
        Route::get('/', [PersonController::class, 'index'])->name('index');
        Route::post('/', [PersonController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('store');
        Route::get('/{publicId}', [PersonController::class, 'show'])
            ->whereUuid('publicId')
            ->name('show');
        Route::get('/{publicId}/participations', [PersonParticipationController::class, 'index'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('participations.index');
        Route::post('/{publicId}/participations', [PersonParticipationController::class, 'store'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('participations.store');
        Route::post('/{publicId}/participations/{participationId}/deactivate', [PersonParticipationController::class, 'deactivate'])
            ->whereUuid('publicId')
            ->whereNumber('participationId')
            ->middleware('auth.permission:people.manage')
            ->name('participations.deactivate');
        Route::get('/{publicId}/person-relationships', [PersonRelationshipController::class, 'index'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('person-relationships.index');
        Route::post('/{publicId}/person-relationships', [PersonRelationshipController::class, 'store'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('person-relationships.store');
        Route::post('/{publicId}/person-relationships/{relationshipId}/deactivate', [PersonRelationshipController::class, 'deactivate'])
            ->whereUuid('publicId')
            ->whereNumber('relationshipId')
            ->middleware('auth.permission:people.manage')
            ->name('person-relationships.deactivate');
        Route::get('/{publicId}/contacts', [PersonContactPointController::class, 'index'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('contacts.index');
        Route::post('/{publicId}/contacts', [PersonContactPointController::class, 'store'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('contacts.store');
        Route::post('/{publicId}/contacts/{contactId}/deactivate', [PersonContactPointController::class, 'deactivate'])
            ->whereUuid('publicId')
            ->whereNumber('contactId')
            ->middleware('auth.permission:people.manage')
            ->name('contacts.deactivate');
        Route::get('/{publicId}/documents', [PersonController::class, 'documents'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('documents.index');
        Route::post('/{publicId}/documents', [PersonController::class, 'linkDocument'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('documents.link');
        Route::delete('/{publicId}/documents/{fileId}', [PersonController::class, 'unlinkDocument'])
            ->whereUuid('publicId')
            ->whereNumber('fileId')
            ->middleware('auth.permission:people.manage')
            ->name('documents.unlink');
        Route::post('/{publicId}/documents/{fileId}/unlink', [PersonController::class, 'unlinkDocument'])
            ->whereUuid('publicId')
            ->whereNumber('fileId')
            ->middleware('auth.permission:people.manage')
            ->name('documents.unlink-command');
        Route::get('/{publicId}/relationships', [PersonController::class, 'relationships'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('relationships');
        Route::get('/{publicId}/relationships/{domain}/options', [PersonController::class, 'relationshipOptions'])
            ->whereUuid('publicId')
            ->whereIn('domain', ['employees', 'shareholders', 'governance-memberships'])
            ->middleware('auth.permission:people.manage')
            ->name('relationships.options');
        Route::post('/{publicId}/relationships/{domain}/{recordId}', [PersonController::class, 'linkDomain'])
            ->whereUuid('publicId')
            ->whereIn('domain', ['employees', 'shareholders', 'governance-memberships'])
            ->whereNumber('recordId')
            ->middleware('auth.permission:people.manage')
            ->name('relationships.link');
        Route::post('/{publicId}/relationships/{domain}/{recordId}/unlink', [PersonController::class, 'unlinkDomain'])
            ->whereUuid('publicId')
            ->whereIn('domain', ['employees', 'shareholders', 'governance-memberships'])
            ->whereNumber('recordId')
            ->middleware('auth.permission:people.manage')
            ->name('relationships.unlink-command');
        Route::delete('/{publicId}/relationships/{domain}/{recordId}', [PersonController::class, 'unlinkDomain'])
            ->whereUuid('publicId')
            ->whereIn('domain', ['employees', 'shareholders', 'governance-memberships'])
            ->whereNumber('recordId')
            ->middleware('auth.permission:people.manage')
            ->name('relationships.unlink');
        // Global account-link permission is enforced by PersonIdentityService.
        Route::post('/{publicId}/account', [PersonController::class, 'linkAccount'])
            ->whereUuid('publicId')
            ->name('account.link');
        Route::delete('/{publicId}/account', [PersonController::class, 'unlinkAccount'])
            ->whereUuid('publicId')
            ->name('account.unlink');
        Route::put('/{publicId}', [PersonController::class, 'update'])
            ->whereUuid('publicId')
            ->middleware('auth.permission:people.manage')
            ->name('update');
});
