<?php

namespace App\Policies;

use App\Models\Eligibility;
use App\Models\User;

/**
 * Eligibility reference data is managed by administrators (ESD) only.
 */
class EligibilityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Eligibility $eligibility): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Eligibility $eligibility): bool
    {
        return $user->isAdmin();
    }
}
