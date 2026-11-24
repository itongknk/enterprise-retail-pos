<?php

namespace App\Modules\IdentityAccess\Models;

use App\Shared\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use HasUuids;
    use BelongsToOrganization;

    protected $table = 'users';

    protected $fillable = [
        'organization_id',
        'employee_code',
        'email',
        'password_hash',
        'pin_hash',
        'full_name',
        'is_org_admin',
        'status',
        'failed_login_attempts',
        'locked_until',
    ];

    protected $hidden = [
        'password_hash',
        'pin_hash',
    ];

    protected $casts = [
        'is_org_admin' => 'boolean',
        'failed_login_attempts' => 'integer',
        'locked_until' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function branchRoleAssignments(): HasMany
    {
        return $this->hasMany(UserBranchRole::class ?? \Illuminate\Database\Eloquent\Model::class, 'user_id');
    }

    public function hasPermissionInContext(string $permissionCode, ?string $branchId = null): bool
    {
        if ($this->status !== 'ACTIVE') {
            return false;
        }

        if ($this->is_org_admin) {
            return true;
        }

        $query = DB::table('user_branch_roles as ubr')
            ->join('roles as r', 'r.id', '=', 'ubr.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ubr.organization_id', $this->organization_id)
            ->where('ubr.user_id', $this->id)
            ->where('p.code', $permissionCode);

        $query->where(function ($q) use ($branchId): void {
            // สิทธิ์ระดับองค์กร (branch_id IS NULL) ใช้ได้ทุกสาขาในองค์กร
            $q->whereNull('ubr.branch_id');

            // สิทธิ์ระดับสาขา ต้องตรงกับ branch_id ที่ระบุ
            if (!empty($branchId)) {
                $q->orWhere('ubr.branch_id', $branchId);
            }
        });

        return $query->exists();
    }
}