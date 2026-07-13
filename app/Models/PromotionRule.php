<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionRule extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'promotion_id',
        'schema_version',
        'condition_type',
        'reward_type',
        'conditions',
        'rewards',
        'stackable',
        'min_spend_centavos',
        'max_applications_per_sale',
        'max_discount_centavos',
        'exclusive_group',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'conditions' => 'array',
        'rewards' => 'array',
        'stackable' => 'boolean',
        'is_active' => 'boolean',
        'min_spend_centavos' => 'integer',
        'max_applications_per_sale' => 'integer',
        'max_discount_centavos' => 'integer',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
