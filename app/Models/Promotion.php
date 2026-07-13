<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'rule_type',
        'priority',
        'starts_at',
        'ends_at',
        'is_active',
        'currency',
        'timezone',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'priority' => 'integer',
    ];

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'promotion_branches');
    }

    public function rules()
    {
        return $this->hasMany(PromotionRule::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDateRange($query, $timestamp = null)
    {
        $time = $timestamp ? \Carbon\Carbon::parse($timestamp) : now();
        return $query->where('starts_at', '<=', $time)
                     ->where('ends_at', '>=', $time);
    }

    public function scopeForBranch($query, string $branchId)
    {
        return $query->where(function ($q) use ($branchId) {
            $q->whereDoesntHave('branches') // global
              ->orWhereHas('branches', function ($bq) use ($branchId) {
                  $bq->where('branches.id', $branchId);
              });
        });
    }
}
