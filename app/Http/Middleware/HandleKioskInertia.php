<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

/**
 * The kiosk's Inertia layer. It shares only what the public screen needs: the
 * terminal's name and office, the timings and the URL base. It deliberately
 * shares nothing from the staff side (no user, no permissions).
 */
class HandleKioskInertia extends Middleware
{
    protected $rootView = 'kiosk';

    /**
     * Page data kept by the browser for Back/Forward is encrypted, so a shared
     * terminal never leaves a visitor's answers readable in its history.
     */
    public function handle(Request $request, Closure $next)
    {
        Inertia::encryptHistory();

        return parent::handle($request, $next);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'terminal' => fn () => ($terminal = $request->attributes->get('kiosk_terminal')) ? [
                'name' => $terminal->name,
                'office' => $terminal->office?->name,
                'office_code' => $terminal->office?->code,
                'files_at' => $terminal->filingOfficeId() === $terminal->office_id ? null : 'Examination Services Division',
            ] : null,
            'kiosk' => [
                'base' => KioskPaths::base(),
                'idleSeconds' => config('etts.kiosk.idle_seconds'),
                'doneSeconds' => config('etts.kiosk.done_seconds'),
            ],
            'flash' => [
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
