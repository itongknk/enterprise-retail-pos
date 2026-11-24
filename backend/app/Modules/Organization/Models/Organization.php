<?php

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasUuids;

    protected $table = 'organizations';

    protected $fillable = [
        'code',
        'name',
        'tax_id',
        'status',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'organization_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'organization_id');
    }

    public function posTerminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class, 'organization_id');
    }
}