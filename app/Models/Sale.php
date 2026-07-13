<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Sale extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    public const TAX_SOURCE_SYSTEM = 'system';
    public const TAX_SOURCE_POS = 'pos';
    public const TAX_SOURCE_MANUAL = 'manual';
    public const TAX_SOURCE_MIGRATION = 'migration';
    public const TAX_SOURCE_UNKNOWN = 'unknown';

    public const REVERSAL_REASON_VOID = 'void';
    public const REVERSAL_REASON_REFUND = 'refund';
    public const REVERSAL_REASON_CORRECTION = 'correction';
    public const REVERSAL_REASON_MANUAL_ADJUSTMENT = 'manual_adjustment';
    public const REVERSAL_REASON_UNKNOWN = 'unknown';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'user_id',
        'client_request_uuid',
        'checkout_request_id',
        'sale_number',
        'status',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'sales_machine_profile_id',
        'principal_invoice_number',
        'principal_invoice_type',
        'principal_invoice_label',
        'invoice_issued_at',
        'reporting_basis_at',
        'gross_sales_amount',
        'vatable_sales_amount',
        'vat_exempt_sales_amount',
        'zero_rated_sales_amount',
        'non_vat_sales_amount',
        'vat_amount',
        'statutory_discount_total',
        'commercial_discount_total',
        'other_adjustment_total',
        'discount_policy_snapshot',
        'contains_statutory_discount',
        'compliance_version',
        'tax_source_version',
        'tax_computation_source',
        'tax_profile_snapshot',
        'source',
        'offline_sales_import_id',
        'offline_sequence_number',
        'offline_submitted_at',
        'offline_local_created_at',
        'offline_posted_at',
        'is_reversal',
        'reversal_of_sale_id',
        'reversal_reason',
        'reversal_tax_impact_snapshot',
        'confirmed_at',
        'receipt_print_count',
        'last_reprint_reason',
        'register_z_read_id',
        'is_training_mode',
    ];

    protected $casts = [
        'subtotal'                    => 'decimal:4',
        'tax_total'                   => 'decimal:4',
        'discount_total'              => 'decimal:4',
        'total'                       => 'decimal:4',
        'invoice_issued_at'           => 'datetime',
        'reporting_basis_at'          => 'datetime',
        'gross_sales_amount'          => 'decimal:4',
        'vatable_sales_amount'        => 'decimal:4',
        'vat_exempt_sales_amount'     => 'decimal:4',
        'zero_rated_sales_amount'     => 'decimal:4',
        'non_vat_sales_amount'        => 'decimal:4',
        'vat_amount'                  => 'decimal:4',
        'statutory_discount_total'    => 'decimal:4',
        'commercial_discount_total'   => 'decimal:4',
        'other_adjustment_total'      => 'decimal:4',
        'discount_policy_snapshot'    => 'array',
        'contains_statutory_discount' => 'boolean',
        'tax_profile_snapshot'        => 'array',
        'offline_submitted_at'         => 'datetime',
        'offline_local_created_at'     => 'datetime',
        'offline_posted_at'            => 'datetime',
        'is_reversal'                 => 'boolean',
        'is_training_mode'            => 'boolean',
        'reversal_tax_impact_snapshot'=> 'array',
        'confirmed_at'                => 'datetime',
        'receipt_print_count'         => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkoutRequest(): BelongsTo
    {
        return $this->belongsTo(CheckoutRequest::class);
    }

    public function offlineSalesImport(): BelongsTo
    {
        return $this->belongsTo(OfflineSalesImport::class, 'offline_sales_import_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function salesMachineProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class, 'sales_machine_profile_id');
    }

    public function statutoryDiscounts(): HasMany
    {
        return $this->hasMany(SaleStatutoryDiscount::class);
    }

    public function saleDiscounts(): HasMany
    {
        return $this->hasMany(SaleDiscount::class);
    }

    public function salePromotions(): HasMany
    {
        return $this->hasMany(SalePromotion::class);
    }

    public function reversalOfSale(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_sale_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_sale_id');
    }

    public static function taxSources(): array
    {
        return [
            self::TAX_SOURCE_SYSTEM,
            self::TAX_SOURCE_POS,
            self::TAX_SOURCE_MANUAL,
            self::TAX_SOURCE_MIGRATION,
            self::TAX_SOURCE_UNKNOWN,
        ];
    }

    public static function taxComputationSources(): array
    {
        return self::taxSources();
    }

    public static function reversalReasons(): array
    {
        return [
            self::REVERSAL_REASON_VOID,
            self::REVERSAL_REASON_REFUND,
            self::REVERSAL_REASON_CORRECTION,
            self::REVERSAL_REASON_MANUAL_ADJUSTMENT,
            self::REVERSAL_REASON_UNKNOWN,
        ];
    }

    protected static function booted()
    {
        static::updating(function ($sale) {
            // Block updates if already locked in a finalized Z-read
            if ($sale->getOriginal('register_z_read_id') !== null) {
                throw new \RuntimeException('Sales locked in a finalized Z-read cannot be modified.');
            }

            // Block updates to invoice numbering, financial totals, and core identity fields per BIR regulations
            $immutableFields = [
                'tenant_id',
                'branch_id',
                'user_id',
                'client_request_uuid',
                'checkout_request_id',
                'sales_machine_profile_id',
                'sale_number',
                'principal_invoice_number',
                'invoice_issued_at',
                'subtotal',
                'tax_total',
                'discount_total',
                'total',
                'gross_sales_amount',
                'vatable_sales_amount',
                'vat_exempt_sales_amount',
                'zero_rated_sales_amount',
                'non_vat_sales_amount',
                'vat_amount',
            ];

            if ($sale->isDirty($immutableFields)) {
                throw new \RuntimeException('Financial totals and core identity of a sale are immutable.');
            }
        });

        static::deleting(function ($sale) {
            throw new \RuntimeException('Sales cannot be deleted. Use void/refund protocols.');
        });
    }
}
