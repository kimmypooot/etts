<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Who did what is for administrators only.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
