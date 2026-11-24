<?php

namespace App\Modules\Shifts\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shifts\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shiftService) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? $request->user()?->organization_id;

        $rows = DB::table('pos_shifts')
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('opened_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($rows);
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $organizationId = $validated['organization_id'] ?? $user?->organization_id;

        if (! $organizationId) {
            return response()->json(['message' => 'organization_id is required.'], 422);
        }

        try {
            $result = $this->shiftService->openShift(
                organizationId: (string) $organizationId,
                branchId: (string) $validated['branch_id'],
                openingCash: (float) $validated['opening_cash'],
                actorId: $user?->id ? (string) $user->id : null,
                note: $validated['note'] ?? null
            );

            return response()->json(['data' => $result], 201);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            return response()->json(['data' => $this->shiftService->getShiftSummary($id)], 200);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'closing_cash_counted' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->shiftService->closeShift(
                shiftId: $id,
                closingCashCounted: (float) $validated['closing_cash_counted'],
                actorId: $request->user()?->id ? (string) $request->user()->id : null,
                note: $validated['note'] ?? null
            );

            return response()->json(['data' => $result], 200);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}