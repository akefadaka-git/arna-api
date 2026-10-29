<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\FileManagement\Http\Controllers\FileController;

/*
 * Intentionally public routes. The controller enforces persisted public +
 * published state on every request. Verification URLs are Laravel-signed to
 * prevent tampering with the file identifier or query parameters.
 */
Route::prefix('/public/files')->name('api.files.public.')->group(static function (): void {
    Route::get('/{id}/download', [FileController::class, 'publicDownload'])
        ->whereNumber('id')
        ->name('download');

    Route::get('/{id}/verify', [FileController::class, 'publicVerify'])
        ->whereNumber('id')
        ->middleware('signed')
        ->name('verify');
});
