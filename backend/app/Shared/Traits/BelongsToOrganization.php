<?php

namespace App\Shared\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getKey())) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }

            if (empty($model->getAttribute('organization_id')) && app()->bound('current_organization_id')) {
                $orgId = app('current_organization_id');
                if (!empty($orgId)) {
                    $model->setAttribute('organization_id', $orgId);
                }
            }
        });

        static::addGlobalScope('organization', function (Builder $builder): void {
            if (app()->bound('current_organization_id')) {
                $orgId = app('current_organization_id');
                if (!empty($orgId)) {
                    $table = $builder->getModel()->getTable();
                    $builder->where("{$table}.organization_id", $orgId);
                }
            }
        });
    }
}