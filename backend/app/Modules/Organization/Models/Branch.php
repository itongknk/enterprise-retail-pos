<?php

namespace App\Modules\Organization\Models;

use App\Shared\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasUuids;
    use BelongsToOrganization;

    protected $table = 'branches';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'timezone',
        'is_headquarters',
        'status',
    ];

    protected $casts = [
        'is_headquarters' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'branch_id');
    }

    public function posTerminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class, 'branch_id');
    }
}