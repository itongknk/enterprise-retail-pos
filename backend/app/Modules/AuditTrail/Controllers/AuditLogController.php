<?php

namespace App\Modules\AuditTrail\Controllers;

use App\Modules\AuditTrail\Services\AuditTrailService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $query = DB::table('audit_logs')->orderByDesc('id');

        foreach (['organization_id', 'branch_id', 'actor_id', 'action', 'entity_type', 'entity_id'] as $col) {
            $val = $request->query($col);
            if ($val !== null && $val !== '') {
                $query->where($col, (string) $val);
            }
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function verifyChain(Request $request): JsonResponse
    {
        $orgFilter = $request->query('organization_id');

        $orgIdsQuery = DB::table('audit_logs')->select('organization_id')->distinct();
        if (! empty($orgFilter)) {
            $orgIdsQuery->where('organization_id', (string) $orgFilter);
        }

        $orgIds = $orgIdsQuery->pluck('organization_id');
        $results = [];
        $allPassed = true;

        foreach ($orgIds as $orgId) {
            $expectedPrevHash = str_repeat('0', 64);
            $checked = 0;
            $errors = [];

            $logs = DB::table('audit_logs')
                ->where('organization_id', $orgId)
                ->orderBy('id')
                ->cursor();

            foreach ($logs as $log) {
                $checked++;

                if ((string) $log->prev_hash !== $expectedPrevHash) {
                    $allPassed = false;
                    $errors[] = "Chain break at audit_logs.id={$log->id}: prev_hash mismatch.";
                }

                $afterData = $log->after_data !== null
                    ? (is_string($log->after_data) ? json_decode($log->after_data, true) : (array) $log->after_data)
                    : null;

                /** @var AuditTrailService $auditTrailService */
                $auditTrailService = app(AuditTrailService::class);
                $afterJson = $auditTrailService->normalizeJson($afterData);
                $createdAtIso = CarbonImmutable::parse((string) $log->created_at)->utc()->toIso8601ZuluString();

                $payloadToHash = implode('|', [
                    (string) $log->prev_hash,
                    (string) $log->organization_id,
                    (string) ($log->branch_id ?? ''),
                    (string) ($log->actor_id ?? ''),
                    (string) $log->action,
                    (string) $log->entity_type,
                    (string) $log->entity_id,
                    $afterJson,
                    $createdAtIso,
                ]);

                $computedHash = hash('sha256', $payloadToHash);

                if (! hash_equals((string) $log->record_hash, $computedHash)) {
                    $allPassed = false;
                    $errors[] = "Hash tamper detected at audit_logs.id={$log->id}.";
                }

                $expectedPrevHash = (string) $log->record_hash;
            }

            $results[] = [
                'organization_id' => (string) $orgId,
                'checked_records' => $checked,
                'passed' => count($errors) === 0,
                'errors' => $errors,
            ];
        }

        return response()->json([
            'passed' => $allPassed,
            'organizations' => $results,
        ], $allPassed ? 200 : 409);
    }
}