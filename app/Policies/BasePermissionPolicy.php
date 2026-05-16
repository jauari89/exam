<?php

namespace App\Policies;

use App\Models\User;

abstract class BasePermissionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    protected function allows(User $user, string $permission): bool
    {
        return $user->canDo($permission);
    }
}
