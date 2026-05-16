<?php

namespace App\Policies;

use App\Models\User;

class ExamPackagePolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'manage_packages') || $this->allows($user, 'view_reports');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage_packages');
    }

    public function update(User $user): bool
    {
        return $this->allows($user, 'manage_packages');
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, 'manage_packages');
    }
}
