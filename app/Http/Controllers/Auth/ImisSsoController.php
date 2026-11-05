<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Signs staff in from the IMIS portal, the only way into ETTS. IMIS sends
 * the browser here with a one-time `?sso_token=`; this redeems it
 * server-to-server at IMIS, which says who it was issued for and what role
 * (`role_code`, a UserRole value) they hold in ETTS.
 *
 * The account here is a stub linked by `imis_user_id`: created on first
 * sign-in and rewritten from IMIS on every sign-in, so a role changed or
 * removed in IMIS takes effect the next time the person opens ETTS.
 */
class ImisSsoController extends Controller
{
    /**
     * Where IMIS sends the browser. Shows the "signing you in" splash at once;
     * the page posts the token back to store() while it stays up.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $token = $request->query('sso_token');

        // Already signed in and no new token: nothing to do. A token is a new IMIS
        // sign-in, which store() lets replace the session the browser still holds.
        if (Auth::check() && ! (is_string($token) && $token !== '')) {
            return redirect()->route('dashboard');
        }

        if (! config('services.imis.sso_url') || ! is_string($token) || $token === '') {
            return $this->refuse('Single sign-on is not available. Please open ETTS from IMIS.');
        }

        return Inertia::render('Auth/ImisSso', ['token' => $token]);
    }

    public function store(Request $request): RedirectResponse
    {
        // A token from IMIS is a new sign-in: whoever's session the browser still
        // holds (one IMIS could not end, say) must not stay signed in as them.
        if (Auth::check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        $url = config('services.imis.sso_url');
        $token = $request->input('sso_token');

        if (! $url || ! is_string($token) || $token === '') {
            return $this->refuse('Single sign-on is not available. Please open ETTS from IMIS.');
        }

        try {
            $response = Http::asForm()->timeout(10)->post($url, [
                'token' => $token,
                'audience' => config('services.imis.audience'),
            ]);
        } catch (Throwable $e) {
            Log::warning('IMIS sign-in failed', ['message' => $e->getMessage()]);

            return $this->refuse('IMIS could not be reached. Please try again in a moment.');
        }

        $imis = $response->successful() && $response->json('success') === true ? $response->json('user') : null;

        if (! is_array($imis) || ! is_int($imis['id'] ?? null) || ! is_string($imis['username'] ?? null)) {
            return $this->refuse('Your IMIS sign-in could not be used here. Please open ETTS from IMIS again.');
        }

        $role = UserRole::tryFrom((string) ($imis['role_code'] ?? ''));

        if ($role === null) {
            return $this->refuse('You have not been given an ETTS role in IMIS. Please contact your administrator.');
        }

        $user = User::where('imis_user_id', $imis['id'])->first() ?? new User;

        if (User::where('username', $imis['username'])->whereKeyNot($user->id)->exists()) {
            return $this->refuse('Your account clashes with an existing ETTS account. Please contact your administrator.');
        }

        $name = trim(trim((string) ($imis['fname'] ?? '')).' '.trim((string) ($imis['lname'] ?? '')));

        $user->fill([
            'imis_user_id' => $imis['id'],
            'username' => $imis['username'],
            'name' => $name ?: $imis['username'],
            'email' => ($imis['email'] ?? '') ?: null,
            'role' => $role,
            'office_id' => Office::forImisCode($imis['office_code'] ?? null)?->id,
            'photo_url' => $this->photoUrl($imis),
            'last_login_at' => now(),
        ])->save();


        AuditLog::record('login', $user, after: ['method' => 'imis', 'role' => $role->value], user: $user, request: $request);

        Auth::login($user);
        $request->session()->regenerate();

        // So signing out of IMIS can end this session (ImisSsoLogoutController).
        if (is_string($imis['sid'] ?? null) && strlen($imis['sid']) === 64) {
            try {
                DB::table('imis_sessions')->insert([
                    'sid_hash' => hash('sha256', $imis['sid']),
                    'session_id' => $request->session()->getId(),
                    'created_at' => now(),
                ]);
            } catch (Throwable $e) {
                // Only the sign-out link is lost; the person still signs in.
                Log::warning('IMIS session could not be recorded', ['message' => $e->getMessage()]);
            }
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * The person's IMIS photo: an http(s) URL that fits the column, or null.
     *
     * @param  array<string, mixed>  $imis
     */
    private function photoUrl(array $imis): ?string
    {
        $url = $imis['photo_url'] ?? null;

        return is_string($url) && strlen($url) <= 255 && preg_match('#^https?://#i', $url) ? $url : null;
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}
