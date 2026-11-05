<?php

namespace App\Policies;

use App\Models\DeletedTransaction;
use App\Models\User;

/**
 * The deleted-records archive is read-only and for ESD administrators.
 */
class DeletedTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, DeletedTransaction $transaction): bool
    {
        return $user->isAdmin();
    }
}
