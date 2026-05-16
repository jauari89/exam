<?php

namespace App\Policies;

use App\Models\User;

class CandidateExamTokenPolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'generate_tokens');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'generate_tokens') || $this->allows($user, 'proctor_sessions');
    }

    public function update(User $user): bool
    {
        return $this->allows($user, 'generate_tokens');
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, 'generate_tokens');
    }
}
