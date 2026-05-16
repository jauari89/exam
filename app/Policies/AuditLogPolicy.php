<?php

namespace App\Policies;

use App\Models\User;

class AuditLogPolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view_audit');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }
}
