<?php

declare(strict_types=1);



/* Loan routes; the provider applies URL prefixes and middleware. */

use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanCollateralController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanProductController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanReconciliationController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanRepaymentController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanScheduleController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\LoanStatementController;
use MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\MyLoanController;

Route::name('api.loans.')->group(function (): void {

    // =========================================================================
    // Product catalogue — MUST be declared before the dynamic `{loan}` route.
    //
    // GET /loans/products
    //
    // See the class docblock §"The route-ORDERING rule" for the rationale
    // on the ordering and the constraint.
    // =========================================================================
    Route::get('products', [LoanProductController::class, 'index'])
        ->name('products.index');

    // =========================================================================
    // Self-service — the authenticated caller's own loans.  (CR-L1)
    //
    // GET /loans/me
    //
    // Declared BEFORE the dynamic `/{loan}` route, for the same reason
    // `/products` is: the static path must be matched first, or the
    // router would capture `me` as `{loan}` and route to
    // LoanController::show(). The ->whereNumber('loan') constraint on
    // the dynamic route provides the second line of defence. See the
    // class docblock §"The route-ORDERING rule".
    //
    // NO route middleware. The endpoint resolves the caller's own loans
    // through a server-side chain that accepts no caller input and
    // cannot be steered at another borrower's row. See
    // MyLoanController's class docblock §"Authorization — why there is
    // no Gate::authorize" for the full reasoning. This mirrors the
    // schedule and statement routes below, which likewise carry no
    // route middleware.
    // =========================================================================
    Route::get('me', [MyLoanController::class, 'index'])
        ->name('me');

    // =========================================================================
    // Loan collection — index and store.
    //
    // GET  /loans
    // POST /loans
    // =========================================================================
    Route::get('/', [LoanController::class, 'index'])
        ->name('index');

    Route::post('/', [LoanController::class, 'store'])
        ->name('store');

    // =========================================================================
    // Loan show — the dynamic single-resource read.
    //
    // GET /loans/{loan}
    //
    // Declared AFTER `/loans/products` and `/loans/me` (see the class
    // docblock §"The route-ORDERING rule").
    // =========================================================================
    Route::get('{loan}', [LoanController::class, 'show'])
        ->whereNumber('loan')
        ->name('show');

    // =========================================================================
    // Lifecycle transitions — every one is a POST.
    //
    // POST /loans/{loan}/approve
    // POST /loans/{loan}/reject
    // POST /loans/{loan}/disburse
    // POST /loans/{loan}/restructure
    // POST /loans/{loan}/writeoff
    // =========================================================================
    Route::post('{loan}/approve', [LoanController::class, 'approve'])
        ->whereNumber('loan')
        ->name('approve');

    Route::post('{loan}/reject', [LoanController::class, 'reject'])
        ->whereNumber('loan')
        ->name('reject');

    Route::post('{loan}/disburse', [LoanController::class, 'disburse'])
        ->whereNumber('loan')
        ->name('disburse');

    Route::post('{loan}/restructure', [LoanController::class, 'restructure'])
        ->whereNumber('loan')
        ->name('restructure');

    Route::post('{loan}/writeoff', [LoanController::class, 'writeoff'])
        ->whereNumber('loan')
        ->name('writeoff');

    // =========================================================================
    // Monetary operation — a repayment is a POST.
    //
    // POST /loans/{loan}/repay
    // =========================================================================
    Route::post('{loan}/repay', [LoanRepaymentController::class, 'store'])
        ->whereNumber('loan')
        ->name('repay');

    // =========================================================================
    // Sub-resource reads — schedule, statement, reconciliation.
    //
    // GET /loans/{loan}/schedule
    // GET /loans/{loan}/statement
    // GET /loans/{loan}/reconciliation
    // =========================================================================
    Route::get('{loan}/schedule', [LoanScheduleController::class, 'show'])
        ->whereNumber('loan')
        ->name('schedule.show');

    Route::get('{loan}/statement', [LoanStatementController::class, 'show'])
        ->whereNumber('loan')
        ->name('statement.show');

    Route::get('{loan}/reconciliation', [LoanReconciliationController::class, 'show'])
        ->whereNumber('loan')
        ->name('reconciliation.show');

    // =========================================================================
    // Collateral — index, store, release.
    //
    // GET  /loans/{loan}/collateral
    // POST /loans/{loan}/collateral
    // POST /loans/{loan}/collateral/{collateral}/release
    // =========================================================================
    Route::get('{loan}/collateral', [LoanCollateralController::class, 'index'])
        ->whereNumber('loan')
        ->name('collateral.index');

    Route::post('{loan}/collateral', [LoanCollateralController::class, 'store'])
        ->whereNumber('loan')
        ->name('collateral.store');

    Route::post(
        '{loan}/collateral/{collateral}/release',
        [LoanCollateralController::class, 'release'],
    )
        ->whereNumber('loan')
        ->whereNumber('collateral')
        ->name('collateral.release');
});