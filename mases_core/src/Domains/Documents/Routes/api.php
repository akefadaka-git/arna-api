<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\Documents\Http\Controllers\DocumentController;

Route::prefix('/documents')->name('api.documents.')->group(static function (): void {
    Route::post('/issue', [DocumentController::class, 'issue'])->name('issue');
    Route::get('/{id}', [DocumentController::class, 'show'])->whereNumber('id')->name('show');
    Route::post('/{id}/void', [DocumentController::class, 'void'])->whereNumber('id')->name('void');
    Route::post('/{id}/revoke', [DocumentController::class, 'revoke'])->whereNumber('id')->name('revoke');
});
