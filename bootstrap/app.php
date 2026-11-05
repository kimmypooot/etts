<?php

use App\Http\Middleware\ConfigureKioskSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleKioskInertia;
use App\Http\Middleware\KioskPaths;
use App\Http\Middleware\KioskSecurityHeaders;
use App\Http\Middleware\RestrictKioskHost;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The public kiosk: its own hosts when configured (and nothing else is
            // served there), otherwise under /kiosk on the main host.
            $domains = config('etts.kiosk.domains');

            if ($domains === []) {
                Route::prefix(ltrim(KioskPaths::base(), '/'))->middleware('kiosk')->group(base_path('routes/kiosk.php'));

                return;
            }

            foreach ($domains as $domain) {
                Route::domain($domain)->middleware('kiosk')->group(base_path('routes/kiosk.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            prepend: [RestrictKioskHost::class],
            append: [HandleInertiaRequests::class],
        );

        // The kiosk is not the staff app: its own session cookie and policy, its
        // own CSRF token, strict headers, and nothing shared from the staff side.
        $middleware->group('kiosk', [
            ConfigureKioskSession::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
            KioskSecurityHeaders::class,
            HandleKioskInertia::class,
        ]);

        // Behind Cloudflare or a load balancer, trust its forwarding headers so the
        // visitor's real address and scheme are known (rate limits, Secure cookies).
        // Off until TRUSTED_PROXIES is set ("*" or a comma-separated list).
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES') ?: null,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Called by IMIS server-to-server; the `sid` is its credential.
        $middleware->validateCsrfTokens(except: ['sso/logout']);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
