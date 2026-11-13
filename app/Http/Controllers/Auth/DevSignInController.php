<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ApplyActiveOffice;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Local development only: signs in as a throwaway stub user without IMIS.
 * It takes no password and is unreachable unless APP_ENV is `local` AND
 * ETTS_DEV_SIGN_IN is true (see enabled()); the route is not even registered
 * otherwise. Production signs in only through ImisSsoController.
 */
class DevSignInController extends Controller
{
    /** Stub accounts get ids in this range so they never meet a real IMIS id. */
    private const ID_BASE = 900_000_000;

    public static function enabled(): bool
    {
        return app()->environment('local') && (bool) config('etts.dev_sign_in');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        $data = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
            'office_id' => ['nullable', 'integer', Rule::exists('offices', 'id')],
        ]);

        $role = UserRole::from($data['role']);
        $office = isset($data['office_id']) ? Office::find($data['office_id']) : null;

        if ($role === UserRole::FieldOffice && $office === null) {
            return back()->withErrors(['office_id' => 'Choose an office for a field office user.']);
        }

        $user = User::updateOrCreate(
            ['imis_user_id' => self::ID_BASE + ($role === UserRole::Admin ? 0 : $office->id)],
            [
                'username' => $role === UserRole::Admin ? 'dev-admin' : 'dev-fo-'.$office->id,
                'name' => $role === UserRole::Admin ? 'Dev Admin' : 'Dev '.$office->name,
                'role' => $role,
                'office_id' => $office?->id,
                'last_login_at' => now(),
            ],
        );

        AuditLog::record('login', $user, after: ['method' => 'dev', 'role' => $role->value], user: $user, request: $request);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(ApplyActiveOffice::SESSION_KEY);

        return redirect()->intended(route('dashboard'));
    }
}
