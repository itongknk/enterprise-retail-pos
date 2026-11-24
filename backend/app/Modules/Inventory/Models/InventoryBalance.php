<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    use HasUuids;

    protected $table = 'inventory_balances';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'product_id',
        'quantity_on_hand',
        'reserved_quantity',
        'reorder_level',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'reorder_level' => 'decimal:3',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}