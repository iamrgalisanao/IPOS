<?php

namespace App\Traits;

use App\Models\Role;
use App\Models\Permission;

trait HasRoles
{
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /**
     * Check if the user has a specific permission.
     */
    public function hasPermission(string $permissionName): bool
    {
        // Platform support users do not receive tenant role permissions for now.
        if (method_exists($this, 'isPlatformSupport') && $this->isPlatformSupport()) {
            return false;
        }

        // Check if user has any role that possesses this permission.
        // Both Role and Permission are tenant-scoped via BelongsToTenant trait.
        return $this->roles()->whereHas('permissions', function ($query) use ($permissionName) {
            $query->where('name', $permissionName);
        })->exists();
    }

    /**
     * Check if the user has any of the specific permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    /**
     * Securely assign a role to the user.
     */
    public function assignRole(Role $role): void
    {
        // Security check: Role must belong to the same tenant as the user.
        if ($this->tenant_id !== $role->tenant_id) {
            throw new \RuntimeException('Cross-tenant role assignment blocked.');
        }

        $this->roles()->syncWithoutDetaching([$role->id]);
    }
}
