<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'status',
        'currency',
        'timezone',
        'tax_mode',
        'receipt_header',
        'receipt_footer',
        'business_registration_number',
        'subscription_metadata'
    ];

    protected $attributes = [
        'currency' => 'PHP',
        'timezone' => 'Asia/Manila',
        'tax_mode' => 'exclusive',
    ];

    protected $casts = [
        'subscription_metadata' => 'array',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
