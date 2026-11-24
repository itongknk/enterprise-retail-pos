<?php

use App\Http\Middleware\RequireBranchPermission;
use App\Modules\Sales\Controllers\SalesController;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireBranchPermission::class . ':branches.view,required')->group(function (): void {
Route::get('/sales', [SalesController::class, 'index']);
Route::get('/sales/{id}', [SalesController::class, 'show']);
Route::post('/sales', [SalesController::class, 'store']);
Route::post('/sales/{id}/void', [SalesController::class, 'void']);
});