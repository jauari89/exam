<?php

namespace App\Policies;

use App\Models\User;

class IncidentReportPolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'manage_incidents') || $this->allows($user, 'proctor_sessions');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage_incidents') || $this->allows($user, 'proctor_sessions');
    }

    public function update(User $user): bool
    {
        return $this->allows($user, 'manage_incidents');
    }
}
