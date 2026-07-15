<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InventoryMovement extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'movement_uuid',
        'movement_schema_version',
        'movement_sequence',
        'branch_id',
        'product_id',
        'branch_inventory_id',
        'original_movement_id',
        'movement_type',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'base_unit_id',
        'source_unit_id',
        'source_quantity',
        'conversion_snapshot',
        'business_date',
        'posted_at',
        'source_type',
        'source_id',
        'reference_number',
        'source_reference',
        'source_effect_key',
        'user_id',
        'reason_code',
        'remarks',
        'metadata',
    ];

    protected $casts = [
        'movement_schema_version' => 'integer',
        'movement_sequence' => 'integer',
        'quantity_change' => 'decimal:4',
        'quantity_before' => 'decimal:4',
        'quantity_after' => 'decimal:4',
        'source_quantity' => 'decimal:4',
        'conversion_snapshot' => 'array',
        'business_date' => 'date',
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($movement) {
            $movement->movement_uuid ??= self::newMovementUuid();
            $movement->movement_schema_version ??= 1;
            $movement->business_date ??= now()->toDateString();
            $movement->posted_at ??= now();
            $movement->source_reference ??= $movement->reference_number;
            $movement->reference_number ??= $movement->source_reference;

            if (!$movement->source_effect_key && $movement->source_type && $movement->source_id) {
                $movement->source_effect_key = self::defaultSourceEffectKey($movement);
            }

            if (!$movement->movement_sequence && $movement->tenant_id && $movement->branch_id && Schema::hasTable('inventory_movement_sequences')) {
                $movement->movement_sequence = self::nextMovementSequence($movement->tenant_id, $movement->branch_id);
            }
        });

        static::updating(function ($movement) {
            throw new \RuntimeException('Inventory movements are immutable and cannot be updated.');
        });

        static::deleting(function ($movement) {
            throw new \RuntimeException('Inventory movements are append-only and cannot be deleted.');
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class, 'branch_inventory_id');
    }

    protected static function newMovementUuid(): string
    {
        if (method_exists(Str::class, 'uuid7')) {
            return (string) Str::uuid7();
        }

        return (string) Str::orderedUuid();
    }

    protected static function defaultSourceEffectKey(self $movement): string
    {
        return implode(':', [
            str_replace('\\', '_', strtolower((string) $movement->source_type)),
            $movement->source_id,
            'product',
            $movement->product_id,
        ]);
    }

    protected static function nextMovementSequence(string $tenantId, string $branchId): int
    {
        $sequence = DB::table('inventory_movement_sequences')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if (!$sequence) {
            DB::table('inventory_movement_sequences')->insert([
                'id' => (string) Str::orderedUuid(),
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'last_sequence' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('inventory_movement_sequences')
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();
        }

        $next = ((int) $sequence->last_sequence) + 1;

        DB::table('inventory_movement_sequences')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->update([
                'last_sequence' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }
}
