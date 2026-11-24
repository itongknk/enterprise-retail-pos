<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventoryService)
    {
    }

    public function balances(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? $request->user()?->organization_id;

        $balances = InventoryBalance::query()
            ->with(['product'])
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')->toString()))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->string('product_id')->toString()))
            ->orderBy('updated_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return response()->json($balances);
    }

    public function movements(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? $request->user()?->organization_id;

        $movements = StockMovement::query()
            ->with(['product'])
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')->toString()))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->string('product_id')->toString()))
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return response()->json($movements);
    }

    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'movement_type' => ['required', 'string', Rule::in(['IN', 'OUT', 'ADJUST', 'TRANSFER_IN', 'TRANSFER_OUT'])],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'reference_type' => ['nullable', 'string', 'max:80'],
            'reference_id' => ['nullable', 'uuid'],
        ]);

        $user = $request->user();
        $organizationId = $validated['organization_id'] ?? $user?->organization_id;

        if (! $organizationId) {
            return response()->json(['message' => 'organization_id is required.'], 422);
        }

        try {
            $result = $this->inventoryService->adjustStock(
                organizationId: $organizationId,
                branchId: $validated['branch_id'],
                productId: $validated['product_id'],
                documentType: $validated['movement_type'],
                quantityDelta: (float) $validated['quantity_delta'],
                actorId: $user?->id,
                note: $validated['note'] ?? null,
                referenceType: $validated['reference_type'] ?? null,
                referenceId: $validated['reference_id'] ?? null,
            );

            return response()->json([
                'data' => $result,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}