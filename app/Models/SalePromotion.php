<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePromotion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'terminal_id',
        'sale_id',
        'promotion_id',
        'promotion_rule_id',
        'promotion_name',
        'rule_type',
        'condition_type',
        'reward_type',
        'priority',
        'stackable',
        'exclusive_group',
        'base_amount_centavos',
        'discount_amount_centavos',
        'rule_snapshot_json',
        'condition_snapshot_json',
        'reward_snapshot_json',
        'calculation_snapshot_json',
        'promotion_rules_version_hash',
    ];

    protected $casts = [
        'rule_snapshot_json' => 'array',
        'condition_snapshot_json' => 'array',
        'reward_snapshot_json' => 'array',
        'calculation_snapshot_json' => 'array',
        'stackable' => 'boolean',
        'priority' => 'integer',
        'base_amount_centavos' => 'integer',
        'discount_amount_centavos' => 'integer',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function promotionRule()
    {
        return $this->belongsTo(PromotionRule::class);
    }

    public function lines()
    {
        return $this->hasMany(SalePromotionLine::class);
    }
}
