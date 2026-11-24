<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\RequireBranchPermission;
use App\Modules\Inventory\Controllers\InventoryController;
use App\Modules\Inventory\Controllers\StockTransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware([
AuthenticateApiToken::class,
RequireBranchPermission::class . ':branches.view,required',
])->group(function () {
Route::get('/inventory/balances', [InventoryController::class, 'balances']);
Route::get('/inventory/movements', [InventoryController::class, 'movements']);
Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);

Route::get('/stock-transfers', [StockTransferController::class, 'index']);
Route::post('/stock-transfers', [StockTransferController::class, 'store']);
Route::post('/stock-transfers/{id}/ship', [StockTransferController::class, 'ship']);
Route::post('/stock-transfers/{id}/receive', [StockTransferController::class, 'receive']);
});