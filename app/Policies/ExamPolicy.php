<?php

namespace App\Policies;

use App\Models\User;

class ExamPolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'manage_exams') || $this->allows($user, 'view_reports');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage_exams');
    }

    public function update(User $user): bool
    {
        return $this->allows($user, 'manage_exams');
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, 'manage_exams');
    }
}
