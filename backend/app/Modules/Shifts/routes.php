<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\RequireBranchPermission;
use App\Modules\Shifts\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware([
AuthenticateApiToken::class,
RequireBranchPermission::class . ':branches.view,required',
])->group(function () {
Route::get('/shifts', [ShiftController::class, 'index']);
Route::post('/shifts/open', [ShiftController::class, 'open']);
Route::get('/shifts/{id}', [ShiftController::class, 'show']);
Route::post('/shifts/{id}/close', [ShiftController::class, 'close']);
});