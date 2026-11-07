<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ApplyActiveOffice;
use App\Models\AuditLog;
use App\Models\Office;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which office a person who works as more than one is acting as right now:
 * the page they choose it on after signing in, and the switch in the header.
 * The offices on offer are the ones IMIS gave them (User::workOfficeIds); a
 * choice outside that list is refused, so this grants nothing.
 */
class OfficeController extends Controller
{
    public function choose(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (count($user->workOfficeIds()) < 2) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/ChooseOffice', [
            'current' => $request->session()->get(ApplyActiveOffice::SESSION_KEY),
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $user = $request->user();
        $offices = $user->workOfficeIds();

        $data = $request->validate(['office_id' => ['required', 'integer']]);
        abort_unless(in_array($data['office_id'], $offices, true), 403);

        $request->session()->put(ApplyActiveOffice::SESSION_KEY, $data['office_id']);

        $office = Office::find($data['office_id']);
        AuditLog::record('office.switched', null, after: ['summary' => "Switched to working as {$office?->name}.", 'office_id' => $data['office_id']]);

        return redirect()->route('dashboard');
    }
}
