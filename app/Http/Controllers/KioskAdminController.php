<?php

namespace App\Http\Controllers;

use App\Http\Middleware\KioskPaths;
use App\Models\KioskPairingCode;
use App\Models\KioskTerminal;
use App\Models\Office;
use App\Services\Kiosk\KioskPairing;
use App\Services\Transactions\WorkflowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where ESD sets up and retires kiosk terminals: issue a one-time code for an
 * office, see which devices are paired and when they were last used, revoke one.
 * The plain code is shown once, right after it is issued, and is never stored.
 */
class KioskAdminController extends Controller
{
    public function __construct(private KioskPairing $pairing) {}

    public function index(Request $request): Response
    {
        $kioskUrl = config('etts.kiosk.domains') === []
            ? url(KioskPaths::base())
            : (($request->isSecure() ? 'https://' : 'http://').config('etts.kiosk.domains')[0]);

        return Inertia::render('Kiosks/Index', [
            'terminals' => KioskTerminal::query()->with('office:id,code,name')->orderByRaw('revoked_at is not null')->orderByDesc('last_seen_at')->get()->map(fn (KioskTerminal $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'office' => $t->office?->code,
                'office_name' => $t->office?->name,
                'files_at_esd' => $t->filingOfficeId() !== $t->office_id,
                'paired_at' => $t->paired_at->toIso8601String(),
                'last_seen_at' => $t->last_seen_at?->toIso8601String(),
                'requests_submitted' => $t->requests_submitted,
                'active' => $t->isActive(),
            ])->all(),
            'pending' => KioskPairingCode::query()->with('office:id,code')->whereNull('used_at')->where('expires_at', '>', now())->orderBy('expires_at')->get()->map(fn (KioskPairingCode $c) => [
                'id' => $c->id,
                'name' => $c->terminal_name,
                'office' => $c->office?->code,
                'expires_at' => $c->expires_at->toIso8601String(),
            ])->all(),
            'issued' => $request->session()->pull('kiosk_issued'),
            'offices' => Office::orderBy('name')->get(['id', 'code', 'name'])->map(fn (Office $o) => ['value' => $o->id, 'label' => "{$o->code} — {$o->name}"])->all(),
            'kioskUrl' => $kioskUrl,
            'pairingMinutes' => config('etts.kiosk.pairing_minutes'),
        ]);
    }

    public function issue(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'name' => ['required', 'string', 'max:80'],
        ]);

        try {
            $issued = $this->pairing->issueCode(Office::findOrFail($data['office_id']), $data['name'], $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        $request->session()->flash('kiosk_issued', [
            'code' => $issued['code'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'name' => $data['name'],
        ]);

        return back();
    }

    public function revoke(Request $request, KioskTerminal $terminal): RedirectResponse
    {
        $this->pairing->revoke($terminal, $request->user());

        return back()->with('status', "{$terminal->name} was revoked. It stops working on its next request.");
    }
}
