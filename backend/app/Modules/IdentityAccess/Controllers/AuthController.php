<?php

namespace App\Modules\IdentityAccess\Controllers;

use App\Modules\AuditTrail\Services\AuditTrailService;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(
        private readonly TokenService $tokenService,
        private readonly AuditTrailService $auditTrail
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'organization_code' => ['nullable', 'string'],
        ]);

        $query = User::withoutGlobalScope('organization')
            ->where('email', strtolower(trim($validated['email'])));

        if (!empty($validated['organization_code'])) {
            $org = DB::table('organizations')->where('code', $validated['organization_code'])->first();
            if (!$org) {
                return response()->json([
                    'error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid credentials.'],
                ], 401);
            }
            $query->where('organization_id', $org->id);
        }

        /** @var User|null $user */
        $user = $query->first();

        if (!$user) {
            return response()->json([
                'error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid credentials.'],
            ], 401);
        }

        $orgId = (string) $user->organization_id;
        app()->instance('current_organization_id', $orgId);

        if ($user->locked_until && now()->lessThan($user->locked_until)) {
            return response()->json([
                'error' => [
                    'code' => 'ACCOUNT_LOCKED',
                    'message' => 'Account temporarily locked due to failed login attempts.',
                ],
            ], 423);
        }

        if ($user->status !== 'ACTIVE' || !Hash::check($validated['password'], (string) $user->password_hash)) {
            $attempts = (int) $user->failed_login_attempts + 1;
            $updateData = ['failed_login_attempts' => $attempts];

            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $updateData['locked_until'] = now()->addMinutes(self::LOCK_MINUTES);
                $updateData['failed_login_attempts'] = 0;
            }

            $user->update($updateData);

            $this->auditTrail->record(
                organizationId: $orgId,
                action: 'AUTH_LOGIN_FAILED',
                entityType: 'user',
                entityId: (string) $user->id,
                beforeData: null,
                afterData: ['email' => $user->email, 'attempts' => $attempts],
                actorId: (string) $user->id,
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent()
            );

            return response()->json([
                'error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid credentials.'],
            ], 401);
        }

        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        $tokens = $this->tokenService->issueTokenPair($user);

        $this->auditTrail->record(
            organizationId: $orgId,
            action: 'AUTH_LOGIN_SUCCESS',
            entityType: 'user',
            entityId: (string) $user->id,
            beforeData: null,
            afterData: ['email' => $user->email],
            actorId: (string) $user->id,
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent()
        );

        return response()->json([
            'data' => array_merge($tokens, [
                'user' => [
                    'id' => (string) $user->id,
                    'organization_id' => $orgId,
                    'employee_code' => $user->employee_code,
                    'email' => $user->email,
                    'full_name' => $user->full_name,
                    'is_org_admin' => (bool) $user->is_org_admin,
                ],
            ]),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        try {
            $tokens = $this->tokenService->rotateRefreshToken($validated['refresh_token']);

            return response()->json([
                'data' => $tokens,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => $e->getMessage() ?: 'REFRESH_FAILED',
                    'message' => 'Unable to refresh token.',
                ],
            ], 401);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('auth_user');
        $refreshToken = $request->input('refresh_token');

        $this->tokenService->revokeAccessToken($request->bearerToken());
        $this->tokenService->revokeRefreshToken(is_string($refreshToken) ? $refreshToken : null);

        if ($user) {
            $this->auditTrail->record(
                organizationId: (string) $user->organization_id,
                action: 'AUTH_LOGOUT',
                entityType: 'user',
                entityId: (string) $user->id,
                beforeData: null,
                afterData: null,
                branchId: $request->attributes->get('branch_id'),
                actorId: (string) $user->id,
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent()
            );
        }

        return response()->json([
            'data' => ['logged_out' => true],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $assignments = DB::table('user_branch_roles as ubr')
            ->join('roles as r', 'r.id', '=', 'ubr.role_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ubr.branch_id')
            ->where('ubr.organization_id', $user->organization_id)
            ->where('ubr.user_id', $user->id)
            ->select([
                'ubr.branch_id',
                'b.code as branch_code',
                'b.name as branch_name',
                'r.id as role_id',
                'r.code as role_code',
                'r.name as role_name',
                'r.scope_level',
            ])
            ->get();

        return response()->json([
            'data' => [
                'id' => (string) $user->id,
                'organization_id' => (string) $user->organization_id,
                'employee_code' => $user->employee_code,
                'email' => $user->email,
                'full_name' => $user->full_name,
                'is_org_admin' => (bool) $user->is_org_admin,
                'assignments' => $assignments,
            ],
        ]);
    }
}