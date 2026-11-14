<?php

namespace App\Policies;

use App\Models\ExamCenter;
use App\Models\User;

/**
 * Places of examination are reference data managed by administrators (ESD) only.
 */
class ExamCenterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ExamCenter $examCenter): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ExamCenter $examCenter): bool
    {
        return $user->isAdmin();
    }
}
