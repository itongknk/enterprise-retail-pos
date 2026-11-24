<?php

namespace App\Modules\Organization\Models;

use App\Shared\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warehouse extends Model
{
    use HasUuids;
    use BelongsToOrganization;

    protected $table = 'warehouses';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'code',
        'name',
        'type',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}