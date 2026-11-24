<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Modules\AuditTrail\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(AuthenticateApiToken::class)->group(function () {
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/verify-chain', [AuditLogController::class, 'verifyChain']);
});