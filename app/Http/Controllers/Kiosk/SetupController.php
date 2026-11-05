<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Middleware\KioskPaths;
use App\Http\Middleware\ResolveKioskTerminal;
use App\Services\Kiosk\KioskPairing;
use App\Services\Transactions\WorkflowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The set-up screen of an unpaired device. It asks for the one-time code ESD
 * issued; there is no password and no staff sign-in here. On success the device
 * keeps a random token in an HttpOnly, SameSite=Strict cookie for years, so a
 * restarted browser or terminal does not need to be set up again.
 */
class SetupController extends Controller
{
    private const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function __construct(private KioskPairing $pairing) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if ($this->pairing->terminalFor($request->cookie(ResolveKioskTerminal::COOKIE)) !== null) {
            return redirect(KioskPaths::base().'/');
        }

        return Inertia::render('Kiosk/Setup');
    }

    public function pair(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']], [
            'code.required' => 'Please enter the set-up code.',
        ]);

        try {
            ['token' => $token] = $this->pairing->redeem($data['code'], $request->ip());
        } catch (WorkflowException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        Cookie::queue(Cookie::make(ResolveKioskTerminal::COOKIE, $token, self::COOKIE_MINUTES, '/', null, $request->isSecure(), true, false, 'strict'));

        return redirect(KioskPaths::base().'/');
    }
}
