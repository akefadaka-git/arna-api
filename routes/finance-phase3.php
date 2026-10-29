<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\Finance\Http\Controllers\YearEndCloseReadinessController;

/*
 * Phase 3 reporting/control endpoint. Read-only; the finance provider loads
 * this file separately so the legacy central API route file is not rewritten.
 */
Route::middleware(['api', 'auth:sanctum', 'tenant.membership'])
    ->prefix('api/finance')
    ->name('api.finance.')
    ->group(static function (): void {
        Route::get('/year-end-close-readiness', YearEndCloseReadinessController::class)
            ->middleware('auth.permission:finance.report.view')
            ->name('year-end-close.readiness');
    });
