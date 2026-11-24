<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $transferService) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? $request->user()?->organization_id;

        $rows = DB::table('stock_transfers')
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'source_branch_id' => ['required', 'uuid'],
            'destination_branch_id' => ['required', 'uuid', 'different:source_branch_id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $organizationId = $validated['organization_id'] ?? $user?->organization_id;

        if (! $organizationId) {
            return response()->json(['message' => 'organization_id is required.'], 422);
        }

        try {
            $result = $this->transferService->createTransfer(
                organizationId: (string) $organizationId,
                sourceBranchId: (string) $validated['source_branch_id'],
                destinationBranchId: (string) $validated['destination_branch_id'],
                items: $validated['items'],
                actorId: $user?->id ? (string) $user->id : null,
                note: $validated['note'] ?? null
            );

            return response()->json(['data' => $result], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function ship(Request $request, string $id): JsonResponse
    {
        try {
            $result = $this->transferService->shipTransfer(
                transferId: $id,
                actorId: $request->user()?->id ? (string) $request->user()->id : null
            );

            return response()->json(['data' => $result], 200);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        try {
            $result = $this->transferService->receiveTransfer(
                transferId: $id,
                actorId: $request->user()?->id ? (string) $request->user()->id : null
            );

            return response()->json(['data' => $result], 200);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}