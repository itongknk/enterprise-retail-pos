<?php

namespace App\Modules\Catalog\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private readonly CatalogService $catalogService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? $request->user()?->organization_id;

        $query = Category::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('code');

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'parent_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $organizationId = $validated['organization_id'] ?? $request->user()?->organization_id;

        if (! $organizationId) {
            return response()->json(['message' => 'organization_id is required.'], 422);
        }

        $category = $this->catalogService->createCategory($organizationId, $validated);

        return response()->json([
            'data' => $category,
        ], 201);
    }
}