<?php

namespace App\Http\Middleware;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(private readonly TokenService $tokenService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (empty($bearer)) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Missing bearer token.',
                ],
            ], 401);
        }

        $tokenMeta = $this->tokenService->resolveAccessToken($bearer);
        if (!$tokenMeta) {
            return response()->json([
                'error' => [
                    'code' => 'TOKEN_INVALID_OR_EXPIRED',
                    'message' => 'Access token is invalid or expired.',
                ],
            ], 401);
        }

        $orgId = (string) $tokenMeta['organization_id'];
        $userId = (string) $tokenMeta['user_id'];

        app()->instance('current_organization_id', $orgId);

        $user = User::query()->where('id', $userId)->first();
        if (!$user || $user->status !== 'ACTIVE') {
            return response()->json([
                'error' => [
                    'code' => 'USER_INACTIVE',
                    'message' => 'User account is inactive or locked.',
                ],
            ], 401);
        }

        $branchId = $request->header('X-Branch-Id');
        if (!empty($branchId)) {
            app()->instance('current_branch_id', (string) $branchId);
        }

        $request->setUserResolver(static fn () => $user);
        $request->attributes->set('auth_user', $user);
        $request->attributes->set('organization_id', $orgId);
        $request->attributes->set('branch_id', $branchId ? (string) $branchId : null);

        return $next($request);
    }
}