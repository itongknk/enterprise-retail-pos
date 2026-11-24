<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Modules\Catalog\Controllers\CategoryController;
use App\Modules\Catalog\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(AuthenticateApiToken::class)->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::patch('/products/{id}', [ProductController::class, 'update']);
});