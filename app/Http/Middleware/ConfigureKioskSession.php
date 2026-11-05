<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives the kiosk its own session, separate from the staff session in name and
 * in policy: a distinct cookie, SameSite=Strict, HttpOnly, Secure whenever the
 * visitor reached us over HTTPS (also behind a proxy that terminates TLS, once
 * it is trusted), and a long lifetime so a terminal left on the menu overnight
 * still has a valid form token in the morning.
 *
 * Runs before the session starts, because the session reads its settings then.
 * The kiosk CSRF token lives in this session and is never the staff token.
 */
class ConfigureKioskSession
{
    public function handle(Request $request, Closure $next): Response
    {
        config([
            'session.cookie' => 'etts_kiosk',
            'session.same_site' => 'strict',
            'session.http_only' => true,
            'session.secure' => $request->isSecure(),
            'session.path' => '/',
            'session.lifetime' => config('etts.kiosk.session_minutes'),
            'session.expire_on_close' => false,
        ]);

        return $next($request);
    }
}
