<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ETTS has no sign-in form: staff open it from IMIS. The "login" page only
 * says so and links back to IMIS, and is where refused sign-ins land.
 */
class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/SignIn', [
            'imisUrl' => config('services.imis.portal_url'),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        AuditLog::record('logout', $request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have signed out of ETTS.');
    }
}
