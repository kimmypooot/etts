<?php

namespace App\Http\Controllers;

use App\Services\Legacy\LegacySync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The "Sync from legacy" screen: pull the legacy database's latest changes in
 * while the old app is still in use. Administrators only, and only while
 * `ETTS_LEGACY_SYNC` is on (see {@see LegacySync}); the `sync-legacy` gate
 * enforces both.
 */
class LegacySyncController extends Controller
{
    public function show(Request $request, LegacySync $sync): Response
    {
        return Inertia::render('LegacySync/Index', [
            ...$sync->status(),
            'result' => $request->session()->get('sync_result'),
        ]);
    }

    public function run(Request $request, LegacySync $sync): RedirectResponse
    {
        try {
            $result = $sync->run($request->user()->username);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The sync stopped with an error. Nothing was removed; see the application log, fix the cause and run it again.');
        }

        return to_route('legacy-sync')->with('sync_result', $result)->with('status', 'Sync finished.');
    }
}
