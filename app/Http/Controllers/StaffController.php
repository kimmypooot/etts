<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff who have signed in through IMIS, with their role and office.
 * Read-only: roles are granted in IMIS.
 */
class StaffController extends Controller
{
    public function __invoke(): Response
    {
        $staff = User::query()
            ->with('office:id,name')
            ->orderByDesc('last_login_at')
            ->orderBy('name')
            ->paginate(50)
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role->label(),
                'office' => $user->office?->name,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('Staff/Index', ['staff' => $staff]);
    }
}
