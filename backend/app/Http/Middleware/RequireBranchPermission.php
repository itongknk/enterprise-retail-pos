<?php

namespace App\Http\Middleware;

use App\Modules\AuditTrail\Services\AuditTrailService;
use App\Modules\IdentityAccess\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireBranchPermission
{
    public function __construct(private readonly AuditTrailService $auditTrail)
    {
    }

    public function handle(Request $request, Closure $next, string $permissionCode, string $branchMode = 'optional'): Response
    {
        /** @var User|null $user */
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        $orgId = (string) $user->organization_id;
        $branchId = $request->header('X-Branch-Id');
        $branchId = !empty($branchId) ? (string) $branchId : null;

        if ($branchMode === 'required' && empty($branchId)) {
            return response()->json([
                'error' => [
                    'code' => 'BRANCH_CONTEXT_REQUIRED',
                    'message' => 'X-Branch-Id header is required for this endpoint.',
                ],
            ], 422);
        }

        if (!empty($branchId)) {
            $branchExistsInOrg = DB::table('branches')
                ->where('id', $branchId)
                ->where('organization_id', $orgId)
                ->exists();

            if (!$branchExistsInOrg) {
                $this->logDenied($request, $orgId, $branchId, (string) $user->id, $permissionCode, 'BRANCH_NOT_IN_ORG');

                return response()->json([
                    'error' => [
                        'code' => 'FORBIDDEN_BRANCH',
                        'message' => 'Branch does not belong to user organization.',
                    ],
                ], 403);
            }
        }

        if (!$user->hasPermissionInContext($permissionCode, $branchId)) {
            $this->logDenied($request, $orgId, $branchId, (string) $user->id, $permissionCode, 'PERMISSION_DENIED');

            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => "Missing required permission: {$permissionCode}",
                ],
            ], 403);
        }

        return $next($request);
    }

    private function logDenied(
        Request $request,
        string $orgId,
        ?string $branchId,
        string $actorId,
        string $permissionCode,
        string $reason
    ): void {
        $this->auditTrail->record(
            organizationId: $orgId,
            action: 'AUTHZ_DENIED',
            entityType: 'permission',
            entityId: $permissionCode,
            beforeData: null,
            afterData: [
                'reason' => $reason,
                'path' => $request->path(),
                'method' => $request->method(),
            ],
            branchId: $branchId,
            actorId: $actorId,
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent()
        );
    }
}