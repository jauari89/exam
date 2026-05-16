<?php

namespace App\Policies;

use App\Models\User;

class QuestionBankPolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'manage_question_bank') || $this->allows($user, 'view_reports');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage_question_bank');
    }

    public function update(User $user): bool
    {
        return $this->allows($user, 'manage_question_bank');
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, 'manage_question_bank');
    }
}
