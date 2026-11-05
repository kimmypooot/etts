<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who has signed in to ETTS is for administrators only. Roles themselves
 * are granted in IMIS.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
