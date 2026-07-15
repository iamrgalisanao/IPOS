<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyReversalSetting extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const PARTIAL_RESTORE_NONE = 'none';
    public const PARTIAL_RESTORE_PROPORTIONAL = 'proportional';
    public const PARTIAL_RESTORE_FULL_WHEN_FULLY_REFUNDED = 'full_when_fully_refunded';

    public const EARN_REVERSAL_ITEM_LINKED = 'item_linked';
    public const EARN_REVERSAL_PROPORTIONAL = 'proportional';
    public const EARN_REVERSAL_ITEM_LINKED_THEN_PROPORTIONAL = 'item_linked_then_proportional';

    public const SETTINGS_SCHEMA_VERSION = 1;

    protected $fillable = [
        'tenant_id',
        'reverse_earned_on_void',
        'reverse_earned_on_refund',
        'restore_redeemed_on_void',
        'restore_redeemed_on_refund',
        'allow_negative_balance',
        'require_approval_for_negative_balance',
        'negative_balance_approval_threshold_points',
        'restore_redeemed_on_partial_refund_policy',
        'refund_earn_reversal_policy',
        'settings_schema_version',
    ];

    protected $casts = [
        'reverse_earned_on_void' => 'boolean',
        'reverse_earned_on_refund' => 'boolean',
        'restore_redeemed_on_void' => 'boolean',
        'restore_redeemed_on_refund' => 'boolean',
        'allow_negative_balance' => 'boolean',
        'require_approval_for_negative_balance' => 'boolean',
        'negative_balance_approval_threshold_points' => 'integer',
        'settings_schema_version' => 'integer',
    ];

    public static function defaults(string $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'reverse_earned_on_void' => true,
            'reverse_earned_on_refund' => true,
            'restore_redeemed_on_void' => true,
            'restore_redeemed_on_refund' => true,
            'allow_negative_balance' => true,
            'require_approval_for_negative_balance' => true,
            'negative_balance_approval_threshold_points' => 0,
            'restore_redeemed_on_partial_refund_policy' => self::PARTIAL_RESTORE_PROPORTIONAL,
            'refund_earn_reversal_policy' => self::EARN_REVERSAL_ITEM_LINKED_THEN_PROPORTIONAL,
            'settings_schema_version' => self::SETTINGS_SCHEMA_VERSION,
        ];
    }
}
