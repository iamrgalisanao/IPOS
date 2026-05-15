<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesMachineProfile extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

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
    ];

    protected $casts = [
        'permit_issued_at' => 'datetime',
        'supplier_accreditation_issued_at' => 'datetime',
        'supplier_accreditation_expires_at' => 'datetime',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'sales_machine_profile_id');
    }
}