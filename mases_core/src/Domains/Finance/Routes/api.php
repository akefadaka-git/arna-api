<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

$controller = \MaaleArna\MASES\Core\Domains\Finance\Http\Controllers\FinanceController::class;

Route::get('/entities', [$controller, 'entities'])->middleware('auth.permission:finance.entity.view')->name('entities.index');
Route::post('/entities', [$controller, 'createEntity'])->middleware('auth.permission:finance.entity.manage')->name('entities.store');

Route::get('/source-mappings', [$controller, 'sourceMappings'])->middleware('auth.permission:finance.entity.view')->name('source-mappings.index');
Route::post('/source-mappings', [$controller, 'requestSourceMapping'])->middleware('auth.permission:finance.entity.manage')->name('source-mappings.store');
Route::post('/source-mappings/{mappingId}/approve', [$controller, 'approveSourceMapping'])->whereNumber('mappingId')->middleware('auth.permission:finance.entity.manage')->name('source-mappings.approve');
Route::post('/source-mappings/{mappingId}/revoke', [$controller, 'revokeSourceMapping'])->whereNumber('mappingId')->middleware('auth.permission:finance.entity.manage')->name('source-mappings.revoke');

Route::get('/accounts', [$controller, 'accounts'])->middleware('auth.permission:finance.account.view')->name('accounts.index');
Route::post('/accounts', [$controller, 'createAccount'])->middleware('auth.permission:finance.account.manage')->name('accounts.store');

Route::get('/periods', [$controller, 'periods'])->middleware('auth.permission:finance.period.view')->name('periods.index');
Route::post('/periods', [$controller, 'createPeriod'])->middleware('auth.permission:finance.period.manage')->name('periods.store');
Route::post('/periods/{periodId}/close', [$controller, 'closePeriod'])->whereNumber('periodId')->middleware('auth.permission:finance.period.manage')->name('periods.close');

Route::get('/journals', [$controller, 'journals'])->middleware('auth.permission:finance.journal.view')->name('journals.index');
Route::post('/journals', [$controller, 'createJournal'])->middleware('auth.permission:finance.journal.create')->name('journals.store');
Route::post('/journals/{journalId}/cancel', [$controller, 'cancelJournal'])->whereNumber('journalId')->middleware('auth.permission:finance.journal.cancel')->name('journals.cancel');
Route::post('/journals/{journalId}/post', [$controller, 'postJournal'])->whereNumber('journalId')->middleware('auth.permission:finance.journal.post')->name('journals.post');
Route::post('/journals/{journalId}/reverse', [$controller, 'reverseJournal'])->whereNumber('journalId')->middleware('auth.permission:finance.journal.reverse')->name('journals.reverse');

Route::get('/trial-balance', [$controller, 'trialBalance'])->middleware('auth.permission:finance.report.view')->name('reports.trial-balance');


$settlementController = \\MaaleArna\\MASES\\Core\\Domains\\Finance\\Http\\Controllers\\GovernancePenaltySettlementController::class;
Route::get('/governance-settlement/account-mappings', [$settlementController, 'accountMappings'])->middleware('auth.permission:finance.account.view')->name('governance-settlement.account-mappings.index');
Route::post('/governance-settlement/account-mappings', [$settlementController, 'requestAccountMapping'])->middleware('auth.permission:finance.account.manage')->name('governance-settlement.account-mappings.store');
Route::post('/governance-settlement/account-mappings/{mappingId}/approve', [$settlementController, 'approveAccountMapping'])->whereNumber('mappingId')->middleware('auth.permission:finance.account.manage')->name('governance-settlement.account-mappings.approve');
Route::post('/governance-settlement/account-mappings/{mappingId}/revoke', [$settlementController, 'revokeAccountMapping'])->whereNumber('mappingId')->middleware('auth.permission:finance.account.manage')->name('governance-settlement.account-mappings.revoke');
Route::get('/governance-settlement/dividend-payables', [$settlementController, 'dividendPayables'])->middleware('auth.permission:finance.entity.view')->name('governance-settlement.dividend-payables.index');
Route::post('/governance-settlement/dividend-payables', [$settlementController, 'createDividendPayable'])->middleware('auth.permission:finance.entity.manage')->name('governance-settlement.dividend-payables.store');
Route::post('/governance-settlement/dividend-payables/{payableId}/approve', [$settlementController, 'approveDividendPayable'])->whereNumber('payableId')->middleware('auth.permission:finance.entity.manage')->name('governance-settlement.dividend-payables.approve');
Route::post('/governance-settlement/settlements/{settlementId}/cash', [$settlementController, 'executeCash'])->whereNumber('settlementId')->middleware('auth.permission:finance.journal.post')->name('governance-settlement.settlements.cash');
Route::post('/governance-settlement/settlements/{settlementId}/dividend-offset', [$settlementController, 'executeDividendOffset'])->whereNumber('settlementId')->middleware('auth.permission:finance.journal.post')->name('governance-settlement.settlements.dividend-offset');
Route::get('/governance-settlement/reconciliation', [$settlementController, 'reconciliation'])->middleware('auth.permission:finance.report.view')->name('governance-settlement.reconciliation');
