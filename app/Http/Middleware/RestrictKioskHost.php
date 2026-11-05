<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A kiosk host serves only the kiosk. This runs on the staff (web) routes, so
 * on a kiosk host name every staff page, the sign-in and the IMIS feed answer
 * 404 as if they did not exist there, whatever the web server is pointed at.
 */
class RestrictKioskHost
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(in_array($request->getHost(), config('etts.kiosk.domains'), true), 404);

        return $next($request);
    }
}
