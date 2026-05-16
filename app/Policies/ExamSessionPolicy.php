<?php

namespace App\Policies;

use App\Models\User;

class ExamSessionPolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'proctor_sessions') || $this->allows($user, 'manage_exams');
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
        return $this->allows($user, 'manage_exams') || $this->allows($user, 'proctor_sessions');
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, 'manage_exams');
    }

    public function operate(User $user): bool
    {
        return $this->allows($user, 'proctor_sessions');
    }
}
