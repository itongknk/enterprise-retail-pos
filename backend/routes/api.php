<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\RequireBranchPermission;
use App\Modules\IdentityAccess\Controllers\AuthController;
use App\Modules\IdentityAccess\Controllers\UserController;
use App\Modules\Sales\Controllers\SalesController;
use App\Modules\Sync\Controllers\SyncPushController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/refresh', [AuthController::class, 'refresh']);

        Route::middleware(AuthenticateApiToken::class)->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    Route::middleware(AuthenticateApiToken::class)->group(function (): void {
        Route::middleware(RequireBranchPermission::class . ':branches.view,optional')
            ->get('/branches', function () {
                return response()->json([
                    'data' => \App\Modules\Organization\Models\Branch::query()->orderBy('code')->get(),
                ]);
            });

        Route::prefix('sales')->group(function (): void {
            Route::middleware(RequireBranchPermission::class . ':branches.view,required')
                ->get('/', [SalesController::class, 'index']);

            Route::middleware(RequireBranchPermission::class . ':branches.view,required')
                ->post('/', [SalesController::class, 'store']);

            Route::middleware(RequireBranchPermission::class . ':branches.view,required')
                ->get('/{id}', [SalesController::class, 'show']);

            Route::middleware(RequireBranchPermission::class . ':branches.view,required')
                ->post('/{id}/void', [SalesController::class, 'void']);
        });

        Route::middleware(RequireBranchPermission::class . ':branches.view,required')
            ->post('/sync/push', SyncPushController::class);
    });
        Route::middleware(AuthenticateApiToken::class)->group(function () : void {
            Route::prefix('users')->group(function () : void {
                Route::middleware(RequireBranchPermission::class . ':branches.view,required')->get('/', [UserController::class, 'index']);
                Route::middleware(RequireBranchPermission::class . ':branches.view,required')->post('/', [UserController::class, 'store']);
                Route::middleware(RequireBranchPermission::class . ':branches.view,required')->get('/{id}', [UserController::class, 'show']);
                Route::middleware(RequireBranchPermission::class . ':branches.view,required')->patch('/{id}', [UserController::class, 'update']);
            });
        });

});
