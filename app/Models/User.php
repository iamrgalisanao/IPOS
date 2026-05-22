<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\BelongsToTenant;
use App\Traits\HasRoles;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasUuids, BelongsToTenant, HasRoles;

    /**
     * The branches assigned to the user.
     */
    public function branches(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Branch::class, 'branch_user');
    }

    /**
     * Assign user to a branch with tenant safety check.
     */
    public function assignToBranch(\App\Models\Branch $branch): void
    {
        if ($this->tenant_id !== $branch->tenant_id) {
            throw new \RuntimeException('Cross-tenant branch assignment blocked.');
        }

        $this->branches()->syncWithoutDetaching([$branch->id]);
    }

    /**
     * Check if user can access a specific branch.
     */
    public function canAccessBranch(\App\Models\Branch $branch): bool
    {
        // Must be same tenant (already enforced by context, but for safety)
        if ($this->tenant_id !== $branch->tenant_id) {
            return false;
        }

        // Global access roles (Owner/Admin, Accountant usually have view_multi_branch_dashboard)
        if ($this->hasPermission('view_multi_branch_dashboard')) {
            return true;
        }

        // Explicit assignment check
        return $this->branches()->where('branch_id', $branch->id)->exists();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'actor_type',
        'tenant_id',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isPlatformSupport(): bool
    {
        return $this->actor_type === 'platform_support';
    }

    public function isTenantUser(): bool
    {
        return $this->actor_type === 'tenant_user';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function shifts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Shift::class, 'cashier_id');
    }

    public function openedShifts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Shift::class, 'opened_by');
    }

    public function approvedShifts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Shift::class, 'approved_by');
    }

    public function closedShifts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Shift::class, 'closed_by');
    }

    public function cashDrawerEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\CashDrawerEvent::class, 'cashier_id');
    }

    public function createdCashDrawerEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\CashDrawerEvent::class, 'created_by');
    }

    /**
     * Determine if the model is an identity-carrying entity.
     * Identity models can be resolved without an active tenant context
     * to facilitate authentication and context establishment.
     */
    public function isIdentityModel(): bool
    {
        return true;
    }
}
