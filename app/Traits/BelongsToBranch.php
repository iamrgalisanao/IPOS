<?php

namespace App\Traits;

use App\Services\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            $branchContext = app(BranchContext::class);
            
            if ($branchContext->hasBranch()) {
                $builder->where($builder->getQuery()->from . '.branch_id', $branchContext->getBranchId());
            }
        });

        static::creating(function (Model $model) {
            $branchContext = app(BranchContext::class);

            if ($branchContext->hasBranch()) {
                $contextBranchId = $branchContext->getBranchId();

                if ($model->branch_id && $model->branch_id !== $contextBranchId) {
                    throw new \RuntimeException('Cross-branch assignment blocked for model: ' . get_class($model));
                }

                $model->branch_id = $contextBranchId;
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }
}
