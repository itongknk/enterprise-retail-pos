<?php

namespace App\Modules\Sync\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncPushController extends Controller
{
    public function __construct(private readonly SalesService $salesService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'device_id' => ['required', 'string', 'max:100'],
            'events' => ['required', 'array', 'min:1'],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.event_type' => ['required', 'string', 'in:SALE_CREATED'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.payload' => ['required', 'array'],
        ]);

        $headerBranchId = trim((string) $request->header('X-Branch-Id', ''));
        if ($headerBranchId === '' || !Str::isUuid($headerBranchId)) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_BRANCH_HEADER',
                    'message' => 'Valid X-Branch-Id UUID header is required.',
                ],
            ], 422);
        }

        if (strtolower($headerBranchId) !== strtolower((string) $validated['branch_id'])) {
            return response()->json([
                'error' => [
                    'code' => 'BRANCH_MISMATCH',
                    'message' => 'X-Branch-Id header must match payload branch_id.',
                ],
            ], 422);
        }

        $authUser = $request->user() ?? $request->attributes->get('auth_user');
        $actorId = is_object($authUser) && isset($authUser->id) ? (string) $authUser->id : null;
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();
        $results = [];

        foreach ($validated['events'] as $event) {
            $existing = DB::table('sync_events')
                ->where('organization_id', $validated['organization_id'])
                ->where('event_id', $event['event_id'])
                ->first();

            if ($existing) {
                $results[] = [
                    'event_id' => $event['event_id'],
                    'status' => 'DUPLICATE',
                    'idempotent_replay' => true,
                    'data' => json_decode($existing->response_payload ?? '{}', true),
                ];
                continue;
            }

            try {
                $txResult = DB::transaction(function () use ($validated, $event, $actorId, $ipAddress, $userAgent) {
                    $payload = $event['payload'];
                    $saleData = $this->salesService->createSale(
                        organizationId: (string) $validated['organization_id'],
                        branchId: (string) $validated['branch_id'],
                        items: (array) ($payload['items'] ?? []),
                        discountAmount: (float) ($payload['discount_amount'] ?? 0.0),
                        payments: (array) ($payload['payments'] ?? []),
                        actorId: $actorId,
                        note: isset($payload['note']) ? (string) $payload['note'] : null,
                        ipAddress: $ipAddress,
                        userAgent: $userAgent,
                        shiftId: isset($payload['shift_id']) ? (string) $payload['shift_id'] : null,
                    );

                    $encoded = json_encode($saleData, JSON_THROW_ON_ERROR);

                    DB::table('sync_events')->insert([
                        'id' => (string) Str::uuid(),
                        'organization_id' => $validated['organization_id'],
                        'branch_id' => $validated['branch_id'],
                        'device_id' => $validated['device_id'],
                        'event_id' => $event['event_id'],
                        'event_type' => $event['event_type'],
                        'status' => 'PROCESSED',
                        'response_payload' => $encoded,
                        'processed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return $saleData;
                });

                $results[] = [
                    'event_id' => $event['event_id'],
                    'status' => 'PROCESSED',
                    'idempotent_replay' => false,
                    'data' => $txResult,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'event_id' => $event['event_id'],
                    'status' => 'FAILED',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Sync batch processed.',
            'data' => [
                'results' => $results,
            ],
        ], 200);
    }
}