<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * FileManagement domain routes.
 *
 * Required from the authenticated, tenant-membership group in routes/api.php.
 * The API prefix and inherited middleware are intentionally preserved.
 */

    // File Management
    // -------------------------------------------------------------------------

    Route::prefix('/files')->name('api.files.')->group(static function (): void {

        /** Upload a file (multipart/form-data). */
        Route::post('/upload', [FileController::class, 'upload'])
            ->name('upload');

        /** List files for the current tenant (paginated, filterable). */
        Route::get('/', [FileController::class, 'index'])
            ->name('index');

        /** Get file metadata (no content). */
        Route::get('/{id}', [FileController::class, 'show'])
            ->name('show');

        /** Download file content (streamed). */
        Route::get('/{id}/download', [FileController::class, 'download'])
            ->name('download');

        /** Update file metadata or replace content (creates a new version). */
        Route::put('/{id}', [FileController::class, 'update'])
            ->name('update');

        /** Soft delete a file (recoverable). */
        Route::delete('/{id}', [FileController::class, 'destroy'])
            ->name('destroy');

        /** Permanently delete a file (hard delete). */
        Route::delete('/{id}/force', [FileController::class, 'forceDestroy'])
            ->name('force-destroy');

        /** Restore a soft-deleted file. */
        Route::post('/{id}/restore', [FileController::class, 'restore'])
            ->name('restore');

        /** Get version history of a file. */
        Route::get('/{id}/versions', [FileController::class, 'versions'])
            ->name('versions');

        /** Get the latest version of a file. */
        Route::get('/{id}/latest', [FileController::class, 'latestVersion'])
            ->name('latest');
    });
