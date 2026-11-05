<?php

namespace App\Policies;

use App\Models\KioskTerminal;
use App\Models\User;

/**
 * Setting up and revoking kiosk terminals is for ESD administrators.
 */
class KioskTerminalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function manage(User $user, ?KioskTerminal $terminal = null): bool
    {
        return $user->isAdmin();
    }
}
