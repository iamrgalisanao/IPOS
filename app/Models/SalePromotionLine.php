<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePromotionLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'sale_promotion_id',
        'sale_item_id',
        'product_id',
        'role',
        'quantity_applied',
        'original_amount_centavos',
        'discount_amount_centavos',
        'final_amount_centavos',
    ];

    protected $casts = [
        'quantity_applied' => 'decimal:3',
        'original_amount_centavos' => 'integer',
        'discount_amount_centavos' => 'integer',
        'final_amount_centavos' => 'integer',
    ];

    public function salePromotion()
    {
        return $this->belongsTo(SalePromotion::class);
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
