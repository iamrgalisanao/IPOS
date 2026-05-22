<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegisterZRead extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sales_machine_profile_id',
        'user_id',
        'z_read_sequence',
        'z_read_date',
        'grand_cumulative_total_before',
        'grand_cumulative_total_after',
        'gross_sales_amount',
        'vatable_sales_amount',
        'vat_exempt_sales_amount',
        'zero_rated_sales_amount',
        'non_vat_sales_amount',
        'vat_amount',
        'statutory_discount_total',
        'commercial_discount_total',
        'other_adjustment_total',
        'void_sales_amount',
        'refund_sales_amount',
        'transaction_count',
        'reset_counter',
        'first_invoice_number',
        'last_invoice_number',
        'reporting_basis_at',
        'is_training_mode',
        'raw_journal_payload',
        'tamper_evident_hash',
    ];

    protected $casts = [
        'z_read_sequence' => 'integer',
        'z_read_date' => 'date',
        'grand_cumulative_total_before' => 'decimal:4',
        'grand_cumulative_total_after' => 'decimal:4',
        'gross_sales_amount' => 'decimal:4',
        'vatable_sales_amount' => 'decimal:4',
        'vat_exempt_sales_amount' => 'decimal:4',
        'zero_rated_sales_amount' => 'decimal:4',
        'non_vat_sales_amount' => 'decimal:4',
        'vat_amount' => 'decimal:4',
        'statutory_discount_total' => 'decimal:4',
        'commercial_discount_total' => 'decimal:4',
        'other_adjustment_total' => 'decimal:4',
        'void_sales_amount' => 'decimal:4',
        'refund_sales_amount' => 'decimal:4',
        'transaction_count' => 'integer',
        'reset_counter' => 'integer',
        'reporting_basis_at' => 'datetime',
        'is_training_mode' => 'boolean',
    ];

    public function salesMachineProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \RuntimeException('RegisterZRead records are immutable and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('RegisterZRead records are immutable and cannot be deleted.');
        });
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'register_z_read_id');
    }
}
