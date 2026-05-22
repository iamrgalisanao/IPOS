<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyOnboardingState extends Model
{
    use HasFactory;

    protected $table = 'company_onboarding_state';

    protected $fillable = [
        'tenant_id',
        'status',
        'initial_branch_id',
        'owner_user_id',
        'owner_email',
        'bootstrap_token',
        'bootstrap_token_expires_at',
        'bootstrap_attempts',
        'bootstrap_locked_until',
        'completed_at',
    ];

    protected $casts = [
        'bootstrap_token_expires_at' => 'datetime',
        'bootstrap_locked_until' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Relationship: Tenant (company)
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * Relationship: Initial Branch
     */
    public function initialBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'initial_branch_id', 'id');
    }

    /**
     * Relationship: Owner User
     */
    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id', 'id');
    }

    /**
     * Relationship: Onboarding Events
     */
    public function events(): HasMany
    {
        return $this->hasMany(CompanyOnboardingEvent::class, 'tenant_id', 'tenant_id');
    }

    /**
     * Get the next action for onboarding progress
     */
    public function getNextAction(): string
    {
        return match($this->status) {
            'provisioned' => 'Create initial branch',
            'branch_created' => 'Create owner user',
            'owner_assigned' => 'Complete bootstrap',
            'ready' => 'Onboarding complete',
            default => 'Unknown',
        };
    }

    /**
     * Get onboarding progress percentage
     */
    public function getProgressPercentage(): int
    {
        return match($this->status) {
            'provisioned' => 0,
            'branch_created' => 33,
            'owner_assigned' => 66,
            'ready' => 100,
            default => 0,
        };
    }

    /**
     * Check if bootstrap is currently locked
     */
    public function isBootstrapLocked(): bool
    {
        if (!$this->bootstrap_locked_until) {
            return false;
        }

        return now() < $this->bootstrap_locked_until;
    }

    /**
     * Check if bootstrap token is expired
     */
    public function isBootstrapTokenExpired(): bool
    {
        if (!$this->bootstrap_token_expires_at) {
            return true;
        }

        return now() > $this->bootstrap_token_expires_at;
    }

    /**
     * Check if bootstrap is complete
     */
    public function isBootstrapComplete(): bool
    {
        return $this->status === 'ready' && !$this->bootstrap_token;
    }

    /**
     * Scope: Find by bootstrap token
     */
    public function scopeByBootstrapToken($query, string $token)
    {
        return $query->where('bootstrap_token', $token);
    }

    /**
     * Scope: Find pending onboarding states
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', ['provisioned', 'branch_created', 'owner_assigned']);
    }

    /**
     * Scope: Find completed onboarding states
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'ready');
    }
}
