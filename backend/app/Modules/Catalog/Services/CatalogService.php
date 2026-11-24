<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\InventoryBalance;
use Illuminate\Support\Facades\DB;

class CatalogService
{
    public function createCategory(string $organizationId, array $data): Category
    {
        return Category::create([
            'organization_id' => $organizationId,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function createProduct(string $organizationId, array $data, ?string $branchId = null): Product
    {
        return DB::transaction(function () use ($organizationId, $data, $branchId) {
            $product = Product::create([
                'organization_id' => $organizationId,
                'category_id' => $data['category_id'] ?? null,
                'sku' => $data['sku'],
                'name' => $data['name'],
                'unit' => $data['unit'] ?? 'pcs',
                'cost_price' => $data['cost_price'] ?? 0,
                'selling_price' => $data['selling_price'] ?? 0,
                'tax_rate' => $data['tax_rate'] ?? 7.00,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $barcodes = $data['barcodes'] ?? [];
            foreach ($barcodes as $index => $item) {
                $barcodeValue = is_array($item) ? $item['barcode'] : $item;
                $isPrimary = is_array($item) ? (bool) ($item['is_primary'] ?? false) : ($index === 0);

                $product->barcodes()->create([
                    'organization_id' => $organizationId,
                    'barcode' => $barcodeValue,
                    'is_primary' => $isPrimary,
                ]);
            }

            if ($branchId) {
                InventoryBalance::firstOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'branch_id' => $branchId,
                        'product_id' => $product->id,
                    ],
                    [
                        'quantity_on_hand' => $data['opening_quantity'] ?? 0,
                        'reserved_quantity' => 0,
                        'reorder_level' => $data['reorder_level'] ?? 0,
                    ]
                );
            }

            return $product->load(['category', 'barcodes', 'inventoryBalances']);
        });
    }

    public function updateProduct(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->update([
                'category_id' => array_key_exists('category_id', $data) ? $data['category_id'] : $product->category_id,
                'sku' => $data['sku'] ?? $product->sku,
                'name' => $data['name'] ?? $product->name,
                'unit' => $data['unit'] ?? $product->unit,
                'cost_price' => $data['cost_price'] ?? $product->cost_price,
                'selling_price' => $data['selling_price'] ?? $product->selling_price,
                'tax_rate' => $data['tax_rate'] ?? $product->tax_rate,
                'is_active' => $data['is_active'] ?? $product->is_active,
            ]);

            return $product->fresh(['category', 'barcodes', 'inventoryBalances']);
        });
    }
}