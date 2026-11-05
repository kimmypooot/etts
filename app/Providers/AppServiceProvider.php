<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Legacy\LegacySync;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Pulling data in from the legacy database: ESD administrators, and only
        // while ETTS_LEGACY_SYNC is on (off once ETTS is the live system).
        Gate::define('sync-legacy', fn (User $user) => $user->isAdmin() && app(LegacySync::class)->enabled());

        // Pairing a kiosk: a code is short, so guessing is slowed hard. Five tries
        // per 15 minutes from one address, and the same code is refused after one use.
        RateLimiter::for('kiosk-pair', fn (Request $request) => Limit::perMinutes(15, 5)->by('pair:'.$request->ip()));

        // A public form that creates records: a person at a counter files a few
        // requests an hour, so this stops a script, not a busy morning. Counted
        // per address and per terminal, so one terminal cannot starve the others.
        RateLimiter::for('kiosk-submit', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(30)->by('terminal:'.($request->attributes->get('kiosk_terminal')?->id ?? $request->ip())),
        ]);
    }
}
