<?php

namespace App\Modules\Catalog\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly CatalogService $catalogService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? $request->user()?->organization_id;

        $products = Product::query()
            ->with(['category', 'barcodes'])
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->string('search')->toString();
                $q->where(function ($sub) use ($term) {
                    $sub->where('sku', 'ilike', "%{$term}%")
                        ->orWhere('name', 'ilike', "%{$term}%")
                        ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', 'ilike', "%{$term}%"));
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'sku' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:200'],
            'unit' => ['nullable', 'string', 'max:30'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'opening_quantity' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'barcodes' => ['nullable', 'array'],
        ]);

        $organizationId = $validated['organization_id'] ?? $request->user()?->organization_id;

        if (! $organizationId) {
            return response()->json(['message' => 'organization_id is required.'], 422);
        }

        $product = $this->catalogService->createProduct(
            $organizationId,
            $validated,
            $validated['branch_id'] ?? null
        );

        return response()->json([
            'data' => $product,
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $product = Product::with(['category', 'barcodes', 'inventoryBalances'])->findOrFail($id);

        return response()->json([
            'data' => $product,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'sku' => ['sometimes', 'string', 'max:80'],
            'name' => ['sometimes', 'string', 'max:200'],
            'unit' => ['nullable', 'string', 'max:30'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->catalogService->updateProduct($product, $validated);

        return response()->json([
            'data' => $updated,
        ]);
    }
}