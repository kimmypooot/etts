<?php

namespace App\Http\Middleware;

use App\Services\Kiosk\KioskPairing;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the paired terminal a request comes from, by the token in its device
 * cookie. A device that is not paired, or whose terminal has been revoked, is
 * sent to the set-up screen (and a dead cookie is cleared), so revoking a
 * terminal takes effect on its very next request.
 */
class ResolveKioskTerminal
{
    public const COOKIE = 'etts_kiosk_device';

    /** How often a terminal's "last seen" is refreshed, to keep a busy kiosk from writing on every click. */
    private const TOUCH_SECONDS = 300;

    public function __construct(private KioskPairing $pairing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $terminal = $this->pairing->terminalFor($request->cookie(self::COOKIE));

        if ($terminal === null) {
            if ($request->cookie(self::COOKIE) !== null) {
                Cookie::queue(Cookie::forget(self::COOKIE));
            }

            return redirect(rtrim(KioskPaths::base(), '/').'/setup');
        }

        if ($terminal->last_seen_at === null || $terminal->last_seen_at->diffInSeconds(now()) > self::TOUCH_SECONDS) {
            $terminal->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('kiosk_terminal', $terminal);

        return $next($request);
    }
}
