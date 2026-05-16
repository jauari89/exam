<?php

namespace App\Policies;

use App\Models\User;

class CandidatePolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'manage_candidates') || $this->allows($user, 'proctor_sessions');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage_candidates');
    }

    public function update(User $user): bool
    {
        return $this->allows($user, 'manage_candidates');
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, 'manage_candidates');
    }
}
