<?php

namespace App\Policies;

use App\Models\User;

class EvidenceExportPolicy extends BasePermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'export_evidence');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'export_evidence');
    }
}
