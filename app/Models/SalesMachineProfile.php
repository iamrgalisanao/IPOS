<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesMachineProfile extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    public const STATUS_PENDING_ACTIVATION = 'pending_activation';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'profile_code',
        'machine_identification_number',
        'machine_serial_number',
        'software_license_number',
        'permit_to_use_number',
        'permit_issued_at',
        'authority_to_generate_control_number',
        'supplier_name',
        'supplier_tin',
        'supplier_branch_code',
        'supplier_address',
        'supplier_accreditation_number',
        'supplier_accreditation_issued_at',
        'supplier_accreditation_expires_at',
        'status',
        'reset_counter',
        'terminal_identifier',
        'last_invoice_sequence',
        'offline_sales_enabled',
        'offline_sequence_prefix',
        'offline_sequence_next_value',
        'offline_sequence_status',
        'last_offline_sync_at',
        'activation_token_hash',
        'activation_token_expires_at',
        'activated_at',
        'activated_by',
        'activated_device_id',
        'activation_status',
        'last_activated_ip',
        // Optional per-terminal layout override (null → falls back to branch-active layout)
        'pos_layout_id',
    ];

    protected $casts = [
        'permit_issued_at' => 'datetime',
        'supplier_accreditation_issued_at' => 'datetime',
        'supplier_accreditation_expires_at' => 'datetime',
        'grand_cumulative_total' => 'decimal:4',
        'reset_counter' => 'integer',
        'z_read_counter' => 'integer',
        'last_invoice_sequence' => 'integer',
        'offline_sales_enabled' => 'boolean',
        'offline_sequence_next_value' => 'integer',
        'last_offline_sync_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function ($profile) {
            if ($profile->isDirty('grand_cumulative_total')) {
                $old = (float) $profile->getOriginal('grand_cumulative_total');
                $new = (float) $profile->grand_cumulative_total;
                if ($new < $old) {
                    throw new \RuntimeException('Grand Cumulative Total (GCT) cannot be decreased.');
                }
            }
            if ($profile->isDirty('z_read_counter')) {
                $old = (int) $profile->getOriginal('z_read_counter');
                $new = (int) $profile->z_read_counter;
                if ($new < $old) {
                    throw new \RuntimeException('z_read_counter cannot be decreased.');
                }
            }
            if ($profile->isDirty('offline_sequence_next_value')) {
                $old = (int) $profile->getOriginal('offline_sequence_next_value');
                $new = (int) $profile->offline_sequence_next_value;
                if ($new < $old) {
                    throw new \RuntimeException('offline_sequence_next_value cannot be decreased.');
                }
            }
        });
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'sales_machine_profile_id');
    }

    /**
     * The optional per-terminal layout override.
     * If set and the layout is published/active, this layout is used instead of the branch-active layout.
     * Returns null (nullOnDelete) when the assigned layout is deleted, causing fallback to branch layout.
     */
    public function posLayout(): BelongsTo
    {
        return $this->belongsTo(PosLayout::class, 'pos_layout_id');
    }
}