<?php

namespace App\Http\Middleware;

/**
 * Where the kiosk lives. On its own host name(s) it is served from the root;
 * with no kiosk host configured (local development) it is served under /kiosk
 * on the main host.
 */
final class KioskPaths
{
    public static function base(): string
    {
        return config('etts.kiosk.domains') === [] ? '/kiosk' : '';
    }
}
