<?php

namespace App\Policies;

use App\Models\PosLayout;
use App\Models\User;

class PosLayoutPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('pos-layouts.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PosLayout $posLayout): bool
    {
        return $user->hasPermission('pos-layouts.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('pos-layouts.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PosLayout $posLayout): bool
    {
        return $user->hasPermission('pos-layouts.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PosLayout $posLayout): bool
    {
        return $user->hasPermission('pos-layouts.manage');
    }

    /**
     * Determine whether the user can publish the model.
     */
    public function publish(User $user, PosLayout $posLayout): bool
    {
        return $user->hasPermission('pos-layouts.publish');
    }
}
