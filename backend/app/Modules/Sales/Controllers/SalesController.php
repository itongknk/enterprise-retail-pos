<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesController extends Controller
{
    public function __construct(
        private readonly SalesService $salesService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $query = DB::table('sales')->orderByDesc('created_at');

        foreach (['organization_id', 'branch_id', 'shift_id', 'status', 'sale_number'] as $col) {
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

    public function show(string $id): JsonResponse
    {
        $sale = DB::table('sales')->where('id', $id)->first();
        if (! $sale) {
            return response()->json(['message' => 'Sale not found.'], 404);
        }

        $items = DB::table('sale_items')->where('sale_id', $id)->get();
        $payments = DB::table('sale_payments')->where('sale_id', $id)->get();

        return response()->json([
            'data' => [
                'sale' => $sale,
                'items' => $items,
                'payments' => $payments,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'shift_id' => ['nullable', 'uuid'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_method' => ['required_with:payments', 'string', 'max:32'],
            'payments.*.amount_tendered' => ['required_with:payments', 'numeric', 'min:0'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $actor = $request->user() ?? $request->attributes->get('auth_user');
            $actorId = is_object($actor) && isset($actor->id) ? (string) $actor->id : null;

            $result = $this->salesService->createSale(
                organizationId: (string) $validated['organization_id'],
                branchId: (string) $validated['branch_id'],
                items: $validated['items'],
                discountAmount: (float) ($validated['discount_amount'] ?? 0),
                payments: $validated['payments'] ?? [],
                actorId: $actorId,
                note: $validated['note'] ?? null,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
                shiftId: isset($validated['shift_id']) ? (string) $validated['shift_id'] : null
            );

            return response()->json([
                'message' => 'Sale created successfully.',
                'data' => $result,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $actor = $request->user() ?? $request->attributes->get('auth_user');
            $actorId = is_object($actor) && isset($actor->id) ? (string) $actor->id : null;

            $result = $this->salesService->voidSale(
                saleId: $id,
                reason: (string) $validated['reason'],
                actorId: $actorId,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            return response()->json([
                'message' => 'Sale voided successfully.',
                'data' => $result,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}