<?php

namespace App\Policies;

use App\Models\User;

class SubmissionAnswerPolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'mark_submissions') || $this->allows($user, 'moderate_marks');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function mark(User $user): bool
    {
        return $this->allows($user, 'mark_submissions');
    }

    public function moderate(User $user): bool
    {
        return $this->allows($user, 'moderate_marks');
    }
}
